<?php
declare(strict_types=1);

// Tiny Markdown subset (safe by default: no HTML passthrough)
// Supports: # ## ###, - lists, > quotes, **bold**, *italic*, _italic_, `code`, ```fences```, [text](url), ![alt](url), pipe tables
// Tipi CMS preview: raw HTML and executable shortcodes are intentionally disabled.
final class MiniMarkdown {
  public static function toHtml($md){
    $md = (string)$md;
    $md = str_replace(array("\r\n","\r"), "\n", $md);
    $lines = explode("\n", $md);

    $html = '';
    $listType = null; // 'ul' ou 'ol'
    $inCode = false;
    $codeLang = '';

    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
      $line = $lines[$i];

      // Fenced code blocks
      if (preg_match('~^```\s*([a-z0-9_-]+)?\s*$~i', $line, $m)) {
        if (!$inCode) {
          $inCode = true;
          $codeLang = isset($m[1]) ? strtolower($m[1]) : '';
          if ($listType) { $html .= "</{$listType}>"; $listType = null; }
          $html .= '<pre class="code"><code'.($codeLang?(' data-lang="'.htmlspecialchars($codeLang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"'):'').'>';
        } else {
          $inCode = false;
          $html .= "</code></pre>";
        }
        continue;
      }

      if ($inCode) {
        $html .= htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        continue;
      }

      $trim = trim($line);

      // Empty line closes list
      if ($trim === '') {
        if ($listType) { $html .= "</{$listType}>"; $listType = null; }
        continue;
      }

      // --- TABLES (pipe tables) ---
      // Detect a table header row + separator row, then consume contiguous rows
      if (self::looksLikeTableRow($trim) && ($i + 1 < $n) && self::isTableSeparator(trim($lines[$i + 1]))) {
        if ($listType) { $html .= "</{$listType}>"; $listType = null; }

        $headerLine = $trim;
        $sepLine = trim($lines[$i + 1]);
        $aligns = self::parseTableAlignments($sepLine);

        $headers = self::splitTableRow($headerLine);
        $rows = [];

        // consume header + separator
        $i += 2;

        // consume body rows while they look like table rows and not blank
        for (; $i < $n; $i++) {
          $rowTrim = trim($lines[$i]);
          if ($rowTrim === '') { $i--; break; } // let outer loop handle blank
          if (!self::looksLikeTableRow($rowTrim)) { $i--; break; }
          $rows[] = self::splitTableRow($rowTrim);
        }

        $html .= self::renderTable($headers, $rows, $aligns);
        continue;
      }

      // Headings
      if (preg_match('~^(#{1,6})\s+(.+)$~', $trim, $m)) {
        if ($listType) { $html .= "</{$listType}>"; $listType = null; }
        $lvl = strlen($m[1]);
        $html .= "<h{$lvl}>" . self::inline($m[2]) . "</h{$lvl}>";
        continue;
      }

      // Lists (ordered then unordered)

      // Ordered list: "1. item"
      if (preg_match('~^\d+\.\s+(.+)$~', $trim, $m)) {
        if ($listType !== 'ol') {
          if ($listType) { $html .= "</{$listType}>"; }
          $html .= "<ol>";
          $listType = 'ol';
        }
        $html .= '<li>' . self::inline($m[1]) . '</li>';
        continue;
      }

      // Unordered list: "- item" or "* item"
      if (preg_match('~^[-*]\s+(.+)$~', $trim, $m)) {
        if ($listType !== 'ul') {
          if ($listType) { $html .= "</{$listType}>"; }
          $html .= "<ul>";
          $listType = 'ul';
        }
        $html .= '<li>' . self::inline($m[1]) . '</li>';
        continue;
      }

      // Blockquotes
      if (preg_match('~^>\s?(.+)$~', $trim, $m)) {
        if ($listType) { $html .= "</{$listType}>"; $listType = null; }
        $html .= '<blockquote>' . self::inline($m[1]) . '</blockquote>';
        continue;
      }

      // ✅ YouTube embed (standalone line) AVANT le paragraphe
      $ytId = self::extractYouTubeId($trim);
      if ($ytId) {
        if ($listType) { $html .= "</{$listType}>"; $listType = null; }
        $html .= self::renderYouTubeEmbed($ytId);
        continue;
      }

      // ✅ YouTube embed (token explicite) AVANT le paragraphe
      if (preg_match('~^\{\{youtube:([a-zA-Z0-9_-]{6,})\}\}$~', $trim, $m)) {
        if ($listType) { $html .= "</{$listType}>"; $listType = null; }
        $html .= self::renderYouTubeEmbed($m[1]);
        continue;
      }

      // hr
      if (preg_match('~^---+$~', $trim)) {
        if ($listType) { $html .= "</{$listType}>"; $listType = null; }
        $html .= '<hr>';
        continue;
      }

      // Paragraph
      if ($listType) { $html .= "</{$listType}>"; $listType = null; }
      $html .= '<p>' . self::inline($trim) . '</p>';
    }

    if ($listType) $html .= "</{$listType}>";
    if ($inCode) $html .= "</code></pre>";

    return $html;
  }

  private static function sanitizeUrl($url){
    $url = trim((string)$url);
    if ($url === '' || preg_match('~[\x00-\x20\x7f\\\\]~', $url)) return '#';
    if (str_starts_with($url, '//')) return '#';
    if ($url[0] === '/' || $url[0] === '#') return $url;
    if (preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL)) return $url;
    return '#';
  }

  private static function inline($s){
    $s = (string)$s;
    $esc = static fn($v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $plain = static fn($v) => self::applyEmphasisOutsideTags($esc($v));
    $pattern = '~\x60([^\x60]+)\x60|(!?)\[([^\]\r\n]*)\]\(([^)\r\n]+)\)~';
    preg_match_all($pattern, $s, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
    $out = ''; $offset = 0;
    foreach ($matches as $m) {
      $start = $m[0][1];
      $out .= $plain(substr($s, $offset, $start - $offset));
      if ($m[1][0] !== null) {
        $out .= '<code>'.$esc($m[1][0]).'</code>';
      } else {
        $url = self::sanitizeUrl($m[4][0]);
        $label = $esc($m[3][0]);
        if ($m[2][0] === '!') {
          $out .= $url === '#' ? $label : '<img src="'.$esc($url).'" alt="'.$label.'" loading="lazy" decoding="async">';
        } else {
          $out .= '<a href="'.$esc($url).'">'.$label.'</a>';
        }
      }
      $offset = $start + strlen($m[0][0]);
    }
    return $out.$plain(substr($s, $offset));
  }

  private static function applyEmphasisOutsideTags($s){
  $parts = preg_split('~(<[^>]+>)~', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
  if (!$parts) return $s;

  for ($i = 0; $i < count($parts); $i++) {
    if ($parts[$i] === '' || $parts[$i][0] === '<') continue;

    $t = $parts[$i];

    // Bold first
    $t = preg_replace('~\*\*([^*]+)\*\*~', '<strong>$1</strong>', $t);

    // Italic with asterisks
    $t = preg_replace('~\*([^*]+)\*~', '<em>$1</em>', $t);

    // Italic with underscores
    $t = preg_replace('~(?<!\w)_(?!_)(.+?)(?<!_)_(?!\w)~', '<em>$1</em>', $t);

    // Small text with ²...²
    $t = preg_replace('~\x{00B2}(.+?)\x{00B2}~u', '<small>$1</small>', $t);

    $parts[$i] = $t;
  }

  return implode('', $parts);
}

  // ---------- TABLE HELPERS ----------

  private static function looksLikeTableRow($line){
    // Needs at least one pipe and some non-pipe content
    if (strpos($line, '|') === false) return false;
    $tmp = trim($line);
    // ignore cases where it's basically just pipes
    return (bool)preg_match('~[^\s|]~', $tmp);
  }

  private static function isTableSeparator($line){
    // Accept forms like:
    // | --- | ---: | :---: |
    // ---|---|---
    if (strpos($line, '|') === false) return false;
    $cells = self::splitTableRow($line);
    if (count($cells) < 1) return false;

    foreach ($cells as $c) {
      $c = trim($c);
      if ($c === '') return false;
      // allow :---, ---:, :---:
      if (!preg_match('~^:?-{3,}:?$~', $c)) return false;
    }
    return true;
  }

  private static function splitTableRow($line){
    // Trim outer pipes then split
    $line = trim($line);
    $line = preg_replace('~^\|~', '', $line);
    $line = preg_replace('~\|$~', '', $line);
    $cells = array_map('trim', explode('|', $line));
    return $cells;
  }

  private static function parseTableAlignments($sepLine){
    $cells = self::splitTableRow($sepLine);
    $aligns = [];
    foreach ($cells as $c) {
      $c = trim($c);
      $left = str_starts_with($c, ':');
      $right = str_ends_with($c, ':');
      if ($left && $right) $aligns[] = 'center';
      else if ($right) $aligns[] = 'right';
      else if ($left) $aligns[] = 'left';
      else $aligns[] = ''; // default
    }
    return $aligns;
  }

  private static function renderTable($headers, $rows, $aligns){
    $colCount = max(count($headers), count($aligns));
    foreach ($rows as $r) $colCount = max($colCount, count($r));

    $out = '<div class="md-table-wrap"><table class="md-table">';

    // THEAD
    $out .= '<thead><tr>';
    for ($i = 0; $i < $colCount; $i++) {
      $txt = $headers[$i] ?? '';
      $al = $aligns[$i] ?? '';
      $style = $al ? ' style="text-align:'.$al.'"' : '';
      $out .= '<th'.$style.'>'.self::inline($txt).'</th>';
    }
    $out .= '</tr></thead>';

    // TBODY
    if (!empty($rows)) {
      $out .= '<tbody>';
      foreach ($rows as $r) {
        $out .= '<tr>';
        for ($i = 0; $i < $colCount; $i++) {
          $txt = $r[$i] ?? '';
          $al = $aligns[$i] ?? '';
          $style = $al ? ' style="text-align:'.$al.'"' : '';
          $out .= '<td'.$style.'>'.self::inline($txt).'</td>';
        }
        $out .= '</tr>';
      }
      $out .= '</tbody>';
    }

    $out .= '</table></div>';
    return $out;
  }

  /**
   * YouTube embed support: detect standalone YouTube URLs and convert to embeds, and also support explicit {{youtube:ID}} tokens.
   */
  private static function extractYouTubeId($s){
    $s = trim((string)$s);

    // Only transform if the whole line looks like a YouTube URL (avoid surprise embeds inside paragraphs)
    if (!preg_match('~^https?://~i', $s)) return null;

    // youtu.be/VIDEO_ID
    if (preg_match('~^https?://(?:www\.)?youtu\.be/([a-zA-Z0-9_-]{6,})~', $s, $m)) {
      return $m[1];
    }

    // youtube.com/watch?v=VIDEO_ID
    if (preg_match('~^https?://(?:www\.)?youtube\.com/watch\?([^#]+)~', $s, $m)) {
      parse_str($m[1], $q);
      if (!empty($q['v']) && preg_match('~^[a-zA-Z0-9_-]{6,}$~', $q['v'])) return $q['v'];
      return null;
    }

    // youtube.com/embed/VIDEO_ID
    if (preg_match('~^https?://(?:www\.)?youtube\.com/embed/([a-zA-Z0-9_-]{6,})~', $s, $m)) {
      return $m[1];
    }

    // youtube.com/shorts/VIDEO_ID
    if (preg_match('~^https?://(?:www\.)?youtube\.com/shorts/([a-zA-Z0-9_-]{6,})~', $s, $m)) {
      return $m[1];
    }

    return null;
  }

  private static function renderYouTubeEmbed($videoId){
    $videoId = trim((string)$videoId);
    if (!preg_match('~^[a-zA-Z0-9_-]{6,}$~', $videoId)) return '<p>[video]</p>';

    // privacy-friendly domain
    $src = 'https://www.youtube-nocookie.com/embed/'.rawurlencode($videoId);

    return
      '<div class="md-video">'.
        '<iframe loading="lazy" src="'.htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" '.
        'title="YouTube video" frameborder="0" '.
        'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '.
        'allowfullscreen></iframe>'.
      '</div>';
  }
}

const TIPI_VERSION = '0.8.0-preview';
const TIPI_SETUP_HASH = '';
const MAX_CONTENT = 262144;

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fail(string $m, int $code = 400): never { throw new RuntimeException($m, $code); }
function jsonout(array $v, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store'); echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}
function redirect(string $p): never { header('Location: '.$p, true, 303); exit; }
function atomic(string $path, string $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) fail('Stockage indisponible', 500);
    $tmp = tempnam($dir, '.tmp-');
    if ($tmp === false) fail('Écriture impossible', 500);
    try {
        if (file_put_contents($tmp, $data) !== strlen($data)) fail('Écriture incomplète', 500);
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) fail('Publication impossible', 500);
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function jread(string $path): array {
    if (!is_file($path)) return [];
    $r = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($r)) fail('Configuration invalide', 500);
    return $r;
}
function jwrite(string $path, array $data): void { atomic($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
function dataDir(): string {
    $override = getenv('TIPI_DATA_DIR');
    $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: dirname(__DIR__));
    $path = $override ?: rtrim($home, '/\\').'/.tipi-cms/'.substr(hash('sha256', __DIR__), 0, 20);
    if (!str_starts_with($path, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $path)) fail('TIPI_DATA_DIR doit être absolu', 500);
    if (is_link($path)) fail('Stockage symbolique interdit', 500);
    $probe = $path;
    while (!file_exists($probe) && dirname($probe) !== $probe) $probe = dirname($probe);
    $real = realpath($probe);
    $root = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: __DIR__) ?: __DIR__;
    $norm = static fn($v) => rtrim(str_replace('\\', '/', $v), '/').'/';
    if ($real === false || str_starts_with(strtolower($norm($real)), strtolower($norm($root)))) fail('Stockage privé hors racine web obligatoire', 500);
    if (preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $path)) fail('Chemin de stockage invalide', 500);
    return $path;
}
function locked(string $dir, callable $fn): mixed {
    $f = fopen($dir.'/write.lock', 'c');
    if (!$f) fail('Verrou indisponible', 500);
    try { if (!flock($f, LOCK_EX)) fail('Verrou indisponible', 500); return $fn(); }
    finally { flock($f, LOCK_UN); fclose($f); }
}
function slugok(string $slug): string {
    if (!preg_match('~^(?:blog/)?[a-z0-9][a-z0-9-]{0,99}$~D', $slug)) fail('Identifiant de page invalide');
    if (in_array($slug, ['admin','api','assets','robots','sitemap','404','mcp','codegraft','codegraft-api'], true)) fail('Identifiant réservé');
    return $slug;
}
function pageurl(string $slug): string { return $slug === 'home' ? '/' : '/'.$slug; }
function front(string $raw): array {
    $raw = str_replace(["\r\n","\r"], "\n", $raw);
    if (!str_starts_with($raw, "---\n") || ($end = strpos($raw,"\n---\n",4)) === false) fail('En-tête Markdown manquant ou invalide');
    $meta = [];
    foreach (explode("\n",substr($raw,4,$end-4)) as $line) {
        if (trim($line)==='' || str_starts_with(trim($line),'#')) continue;
        if (!preg_match('~^([a-z_][a-z0-9_]*):[ \t]*(.*)$~D',$line,$m)) fail('Métadonnée invalide');
        if (isset($meta[$m[1]])) fail('Métadonnée dupliquée');
        $meta[$m[1]]=trim($m[2]);
    }
    if (empty($meta['title'])) fail('Le titre est obligatoire');
    return [$meta, substr($raw,$end+5)];
}
function encodeDoc(array $meta, string $body): string {
    $lines=['---'];
    foreach($meta as $k=>$v) {
        if (!preg_match('~^[a-z_][a-z0-9_]*$~D',(string)$k) || !is_scalar($v) || preg_match('~[\r\n\x00]~',(string)$v)) fail('Métadonnée invalide');
        $lines[]=$k.': '.(string)$v;
    }
    return implode("\n",$lines)."\n---\n".$body;
}
function rawDoc(string $title, string $body, array $extra=[]): string {
    return encodeDoc(array_merge(['title'=>$title,'description'=>'','date'=>gmdate('Y-m-d')],$extra),$body);
}
function fileDoc(string $d,string $slug,string $state): string { return $d.'/'.$state.'/'.slugok($slug).'.md'; }
function loadDoc(string $d,string $slug,string $state): ?string {
    $f=fileDoc($d,$slug,$state); return is_file($f)?(string)file_get_contents($f):null;
}
function effective(string $d,string $slug): ?string { return loadDoc($d,$slug,'drafts') ?? loadDoc($d,$slug,'published'); }
function sha(?string $raw): string { return $raw===null?'':hash('sha256',$raw); }
function inventory(string $d,string $state='published'): array {
    $out=[];
    foreach (['','blog/'] as $sub) foreach (glob($d.'/'.$state.'/'.$sub.'*.md')?:[] as $f) {
        $slug=$sub.basename($f,'.md');
        try { slugok($slug); [$meta]=front((string)file_get_contents($f)); $out[$slug]=$meta; } catch(RuntimeException $ignored) {}
    }
    ksort($out); return $out;
}
function snapshot(string $d,string $slug): string {
    $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(6));
    jwrite($d.'/history/'.$slug.'/'.$id.'.json', ['slug'=>$slug,'published'=>loadDoc($d,$slug,'published'),'draft'=>loadDoc($d,$slug,'drafts'),'time'=>gmdate(DATE_ATOM)]);
    return $id;
}
function saveDoc(string $d,string $slug,string $raw,string $expected,bool $publish): array {
    slugok($slug);
    if (strlen($raw)>MAX_CONTENT || !preg_match('//u',$raw)) fail('Contenu trop volumineux ou UTF-8 invalide');
    [$meta,$body]=front($raw);
    return locked($d,function()use($d,$slug,$meta,$body,$expected,$publish){
        $current=effective($d,$slug);
        if (!hash_equals(sha($current),$expected)) fail('Conflit de version : rechargez le document',409);
        if ($current!==null) { [$prev]=front($current); $meta['id']=$prev['id']??bin2hex(random_bytes(12)); }
        else $meta['id']=bin2hex(random_bytes(12));
        unset($meta['published_at']);
        $meta['modified']=gmdate(DATE_ATOM);
        if ($publish) {
            $live=loadDoc($d,$slug,'published');
            $meta['published_at']=$live!==null?(front($live)[0]['published_at']??gmdate(DATE_ATOM)):gmdate(DATE_ATOM);
        }
        $raw=encodeDoc($meta,$body);
        $rev=snapshot($d,$slug);
        atomic(fileDoc($d,$slug,$publish?'published':'drafts'),$raw);
        if ($publish && is_file(fileDoc($d,$slug,'drafts'))) unlink(fileDoc($d,$slug,'drafts'));
        return ['slug'=>$slug,'sha256'=>sha($raw),'revision'=>$rev,'status'=>$publish?'published':'draft','url'=>pageurl($slug)];
    });
}
function documentState(string $d,string $slug): string {
    return hash('sha256',json_encode([sha(loadDoc($d,$slug,'published')),sha(loadDoc($d,$slug,'drafts'))],JSON_THROW_ON_ERROR));
}
function trashDocuments(string $d,array $items): array {
    if(!array_is_list($items)||count($items)<1||count($items)>100)fail('Sélectionner de 1 à 100 documents');
    return locked($d,function()use($d,$items){
        $selected=[];
        foreach($items as $item){
            if(!is_array($item)||!is_string($item['slug']??null)||!is_string($item['state_sha256']??null))fail('Slug et empreinte de chaque document requis');
            $slug=slugok($item['slug']);
            if(isset($selected[$slug]))fail('Document dupliqué');
            $live=loadDoc($d,$slug,'published');$draft=loadDoc($d,$slug,'drafts');
            if($live===null&&$draft===null)fail('Document introuvable : '.$slug,404);
            if(!hash_equals(documentState($d,$slug),$item['state_sha256']))fail('Document modifié : '.$slug,409);
            $selected[$slug]=['published'=>$live,'drafts'=>$draft];
        }
        $id=gmdate('YmdHis').'-'.bin2hex(random_bytes(6));
        $record=['id'=>$id,'time'=>gmdate(DATE_ATOM),'documents'=>[]];
        foreach($selected as $slug=>$states)$record['documents'][$slug]=['revision'=>snapshot($d,$slug),'title'=>front($states['drafts']??$states['published'])[0]['title']];
        jwrite($d.'/trash/'.$id.'.json',$record);
        try{
            foreach($selected as $slug=>$states)foreach($states as $state=>$raw)if($raw!==null&&!unlink(fileDoc($d,$slug,$state)))fail('Suppression impossible',500);
        }catch(Throwable $ex){
            foreach($selected as $slug=>$states)foreach($states as $state=>$raw)if($raw!==null)atomic(fileDoc($d,$slug,$state),$raw);
            throw $ex;
        }
        return ['status'=>'trashed','batch'=>$id,'documents'=>$record['documents'],'count'=>count($selected),'restorable'=>true];
    });
}
function trashList(string $d): array {
    $files=glob($d.'/trash/*.json')?:[];rsort($files);$out=[];$seen=[];
    foreach(array_slice($files,0,100) as $f){
        $batch=jread($f);
        foreach($batch['documents']??[] as $slug=>$doc){
            if(isset($seen[$slug])||effective($d,$slug)!==null)continue;
            $seen[$slug]=true;
            $out[]=['slug'=>$slug,'title'=>$doc['title'],'revision'=>$doc['revision'],'batch'=>$batch['id'],'deleted_at'=>$batch['time']];
        }
    }
    return $out;
}
function restoreDoc(string $d,string $slug,string $rev,string $expected): void {
    slugok($slug);
    if (!preg_match('~^\d{14}-[a-f0-9]{12}$~D',$rev)) fail('Révision invalide');
    locked($d,function()use($d,$slug,$rev,$expected){
        if (!hash_equals(sha(effective($d,$slug)),$expected)) fail('Conflit de version',409);
        $s=jread($d.'/history/'.$slug.'/'.$rev.'.json');
        if (($s['slug']??null)!==$slug || !array_key_exists('published',$s)||!array_key_exists('draft',$s)) fail('Révision inconnue',404);
        snapshot($d,$slug);
        foreach (['published'=>'published','drafts'=>'draft'] as $state=>$key) {
            $f=fileDoc($d,$slug,$state);
            if (is_string($s[$key])) atomic($f,$s[$key]); elseif(is_file($f)) unlink($f);
        }
    });
}
function noindex(array $m): bool { return in_array(strtolower($m['noindex']??''),['true','yes','1','on'],true); }
function siteurl(string $s): string {
    $p=parse_url($s);
    if (!$p || !isset($p['scheme'],$p['host']) || isset($p['user'],$p['pass'],$p['query'],$p['fragment']) || !in_array($p['path']??'',['','/'],true)) fail('URL de domaine invalide');
    if (array_intersect(['user','pass','query','fragment'],array_keys($p))) fail('URL de domaine invalide');
    if ($p['scheme']!=='https' && !($p['scheme']==='http' && in_array($p['host'],['localhost','127.0.0.1','[::1]'],true))) fail('HTTPS obligatoire sauf test local');
    if (!filter_var($s,FILTER_VALIDATE_URL)) fail('URL invalide');
    return rtrim($s,'/');
}
function sessionStart(): void {
    if(session_status()===PHP_SESSION_ACTIVE)return;
    ini_set('session.use_strict_mode','1');
    session_name('tipi_cms_'.substr(hash('sha256',__DIR__),0,12));
    session_set_cookie_params(['path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
}
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function verifyCsrf(): void { if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) fail('Requête non autorisée',403); }
function field(string $name,string $label,string $value='',string $type='text'): string {
    return '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'" required></label>';
}
function css(): string {
    return 'html{font:17px/1.65 system-ui,sans-serif;color:#182c23;background:#f7f8f5}*{box-sizing:border-box}body{margin:0}header,main,footer{max-width:900px;margin:auto;padding:24px}header{display:flex;flex-wrap:wrap;justify-content:space-between;gap:16px;border-bottom:1px solid #dce3d9}nav a{margin-right:16px}a{color:#176044}h1,h2,h3{line-height:1.2}h1{font-size:clamp(2rem,5vw,3.5rem)}main{min-height:65vh}label{display:block;margin:14px 0}input,textarea,select{display:block;width:100%;padding:12px;border:1px solid #aebbb1;border-radius:6px;background:white;color:#182c23;font:inherit}textarea{min-height:360px;font:14px/1.6 monospace}button,.btn{display:inline-block;background:#244d39;color:white;padding:10px 18px;border:0;border-radius:6px;text-decoration:none;cursor:pointer;font:inherit;margin:5px 5px 5px 0}button.secondary{background:#65796a}img{max-width:100%;height:auto}pre{overflow:auto;padding:14px;background:#e7ede5}code{overflow-wrap:anywhere}table{width:100%;border-collapse:collapse}td,th{padding:8px;text-align:left;border-bottom:1px solid #dce3d9}.panel{padding:24px;background:white;border:1px solid #dce3d9;border-radius:12px}.muted{color:#53645b}footer{border-top:1px solid #dce3d9}iframe{max-width:100%}.md-table-wrap{overflow:auto}';
}
function design(array $cfg): array {
    $themes=['forest'=>['#176044','#244d39','#f7f8f5','#182c23'],'ink'=>['#2454a6','#1c3156','#f8fafc','#162339'],'sand'=>['#8b4b23','#75401f','#fffaf1','#35291d']];
    $v=$themes[$cfg['theme']??'forest']??$themes['forest'];
    $accent=$cfg['accent']??''; if(is_string($accent)&&preg_match('~^#[a-fA-F0-9]{6}$~D',$accent))$v[0]=$v[1]=$accent;
    return $v;
}
function siteDesign(array $cfg): array {
    return ['name'=>$cfg['name']??'Tipi CMS','theme'=>$cfg['theme']??'forest','accent'=>$cfg['accent']??'','layout'=>$cfg['layout']??'classic','custom_css'=>$cfg['custom_css']??''];
}
function designHash(array $cfg): string {return hash('sha256',json_encode(siteDesign($cfg),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
function changeDesign(string $d,array $values,string $expected): array {
    if(array_diff(array_keys($values),array_keys(siteDesign([]))))fail('Propriété de design inconnue');
    foreach($values as $v)if(!is_string($v)||!preg_match('//u',$v))fail('Valeur invalide');
    if(isset($values['name'])&&(trim($values['name'])===''||strlen($values['name'])>120||preg_match('~[\r\n\x00]~',$values['name'])))fail('Nom invalide');
    if(isset($values['theme'])&&!in_array($values['theme'],['forest','ink','sand'],true))fail('Palette inconnue');
    if(isset($values['accent'])&&$values['accent']!==''&&!preg_match('~^#[a-fA-F0-9]{6}$~D',$values['accent']))fail('Couleur invalide');
    if(isset($values['layout'])&&!in_array($values['layout'],['classic','wide','centered','editorial'],true))fail('Disposition inconnue');
    if(isset($values['custom_css'])&&(strlen($values['custom_css'])>30000||preg_match('~[<>\\\\\x00]|@import|url\s*\(|expression\s*\(|/\*~i',$values['custom_css'])))fail('CSS invalide : HTML, imports, URL, commentaires et échappements interdits');
    return locked($d,function()use($d,$values,$expected){
        $c=jread($d.'/config.json');
        if(!hash_equals(designHash($c),$expected))fail('Le design a changé : rechargez',409);
        $rev=gmdate('YmdHis').'-'.bin2hex(random_bytes(6));
        jwrite($d.'/design-history/'.$rev.'.json',siteDesign($c));
        $c=array_replace($c,$values);jwrite($d.'/config.json',$c);
        return ['design'=>siteDesign($c),'sha256'=>designHash($c),'revision'=>$rev];
    });
}
// Native Tipi integration: independent of editable themes and extensions.
const TIPI_ANALYTICS_ORIGIN='https://analitycs.le-tipi.club';
const TIPI_OPTIMIZE_ORIGIN='https://optimize.le-tipi.club';
function tipiHubConfig(string $d): array {return jread($d.'/hub.json');}
function tipiHubEnabled(array $h): bool {return ($h['analytics']??false)===true&&is_string($h['tracker_key']??null)&&preg_match('~^[a-zA-Z0-9_-]{16,128}$~D',$h['tracker_key'])===1;}
function tipiHubBinding(array $h): string {return substr(hash('sha256',($h['installation_uid']??'').'|'.($h['consent_epoch']??'').'|'.($h['tracker_key']??'').'|'.(!empty($h['optimize'])?'1':'0')),0,32);}
function tipiHubConsent(array $h): bool {return tipiHubEnabled($h)&&hash_equals(tipiHubBinding($h).'.yes',(string)($_COOKIE['tipi_metrics_consent']??''));}
function tipiHubStatus(string $d): array {
    $h=tipiHubConfig($d);
    return ['mode'=>tipiHubEnabled($h)?'manual':'disconnected','analytics'=>tipiHubEnabled($h),'optimize'=>tipiHubEnabled($h)&&!empty($h['optimize']),'consent'=>'explicit_opt_in','html_contract'=>'data-ta-event / data-ta-revenue / data-ta-meta-*','javascript'=>'Tipi.track(name, meta) delegates to TipiAnalitycs.event','php'=>'Tipi::track(name, meta, stable_reference) queues confirmed public POST events after consent','server_queue_pending'=>count(glob($d.'/hub-outbox/*.json')?:[]),'pairing_ready'=>false,'revenue_provider_ready'=>false];
}
function tipiHubSettings(string $d,array $post): void {
    $key=trim((string)($post['tracker_key']??''));
    if($key!==''&&!preg_match('~^[a-zA-Z0-9_-]{16,128}$~D',$key))fail('Clé de suivi invalide');
    locked($d,function()use($d,$post,$key){
        $h=tipiHubConfig($d);$old=$h;
        if(($post['action']??'')==='hub_disconnect'){
            $h['analytics']=false;$h['optimize']=false;$h['tracker_key']='';
        }else{
            $h['tracker_key']=$key!==''?$key:($h['tracker_key']??'');
            $h['analytics']=isset($post['analytics']);$h['optimize']=$h['analytics']&&isset($post['optimize']);
            if($h['analytics']&&!tipiHubEnabled($h))fail('Clé de suivi nécessaire');
        }
        $h['installation_uid']=$h['installation_uid']??bin2hex(random_bytes(16));
        if(!isset($h['consent_epoch'])||($old['tracker_key']??'')!==$h['tracker_key']||!empty($old['analytics'])!==!empty($h['analytics'])||!empty($old['optimize'])!==!empty($h['optimize'])){
            $h['consent_epoch']=bin2hex(random_bytes(12));
        }
        jwrite($d.'/hub.json',$h);
    });
}
function tipiHubPanel(string $d): string {
    $h=tipiHubConfig($d);$status=tipiHubStatus($d);
    return '<h1>Le Tipi</h1><div class="panel"><h2>'.(tipiHubEnabled($h)?'Suivi configuré':'Connecter votre site').'</h2><p>Configuration locale provisoire. Récupérez la clé publique du site dans le Tipi Hub. L’association en un clic sera ajoutée côté Hub ultérieurement.</p><form method="post">'.csrf().'<label>Clé publique Analytics (laisser vide pour conserver)<input name="tracker_key" autocomplete="off" placeholder="'.(!empty($h['tracker_key'])?'Clé enregistrée':'Clé du site').'"></label><label><input style="display:inline;width:auto" type="checkbox" name="analytics" '.(!empty($h['analytics'])?'checked':'').'> Activer Analytics sur le site public</label><label><input style="display:inline;width:auto" type="checkbox" name="optimize" '.(!empty($h['optimize'])?'checked':'').'> Activer Optimize après consentement</label><p>Le suivi reste désactivé tant que le visiteur n’a pas accepté. L’administration et les prévisualisations sont exclues.</p><button name="action" value="hub_save">Enregistrer</button><button class="secondary" name="action" value="hub_disconnect">Déconnecter</button></form></div><h2>Événements métier</h2><p>'.(int)$status['server_queue_pending'].' événement(s) PHP en attente. Les clics HTML et événements JS utilisent directement le tracker existant.</p><form method="post">'.csrf().'<button name="action" value="hub_flush" class="secondary">Réessayer les envois PHP</button></form><p><a href="https://le-tipi.club/dashboard">Ouvrir le Tipi Hub</a></p><p>La réception des événements n’est pas confirmée par la simple présence de la clé. Vérifiez le flux temps réel du Hub.</p>';
}
function tipiHubRender(string $html,array $cfg): string {
    $h=tipiHubConfig(dataDir());if(!tipiHubEnabled($h))return $html;
    // No public tracking on admin/preview; shell calls this only with public design config.
    header('Cache-Control: private, no-store');header('Vary: Cookie',false);
    $consented=tipiHubConsent($h);$binding=tipiHubBinding($h);
    $script='<script defer src="/tipi-metrics.js" data-tipi-consent="'.e($binding).'" data-tipi-enabled="'.($consented?'1':'0').'"></script>';
    if($consented){
        $origins=TIPI_ANALYTICS_ORIGIN.(!empty($h['optimize'])?' '.TIPI_OPTIMIZE_ORIGIN:'');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https:; frame-src https://www.youtube-nocookie.com; script-src 'self' ".$origins."; connect-src 'self' ".$origins."; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $script.='<script defer src="'.TIPI_ANALYTICS_ORIGIN.'/tracker.js" data-site-key="'.e($h['tracker_key']).'" data-cookie-domain="'.e((string)parse_url($cfg['url'],PHP_URL_HOST)).'" data-disable-optimize="1" data-disable-payments="1" data-disable-url-revenue="1"></script>';
        if(!empty($h['optimize']))$script.='<script defer src="'.TIPI_OPTIMIZE_ORIGIN.'/optimize.js" data-site-key="'.e($h['tracker_key']).'"></script>';
    }
    $decision=(string)($_COOKIE['tipi_metrics_consent']??'');
    $panel='<aside id="tipi-metrics-consent" style="position:fixed;bottom:12px;right:12px;z-index:2147483647;max-width:min(390px,calc(100vw - 24px));padding:14px;background:#fff;color:#182c23;border:1px solid #bbb;border-radius:8px;font:14px/1.5 system-ui">';
    if($consented||hash_equals($binding.'.no',$decision))$panel.='<button type="button" data-tipi-consent-choice="reset">Préférences de mesure d’audience</button>';
    else $panel.='<p>Autorisez-vous la mesure d’audience'.(!empty($h['optimize'])?' et les tests de présentation':'').' ? Le site reste utilisable sans ces mesures.</p><button type="button" data-tipi-consent-choice="yes">Accepter</button><button type="button" data-tipi-consent-choice="no">Refuser</button>';
    $panel.='</aside>';
    // Inject after theme rendering, including custom layouts. The SDK precedes theme scripts.
    $head=preg_replace_callback('~<head(?:\s[^>]*)?>~i',static fn($m)=>$m[0].$script,$html,1,$n);
    $html=$n?$head:$script.$html;
    $body=preg_replace_callback('~</body\s*>~i',static fn($m)=>$panel.$m[0],$html,1,$n);
    return $n?$body:$html.$panel;
}
function tipiMetricsJs(): string {
    return <<<'JS'
(function () {
  'use strict';
  var script = document.currentScript;
  // document.currentScript is available for classic deferred scripts.
  if (!script) return;
  var binding = script.getAttribute('data-tipi-consent') || '';
  var enabled = script.getAttribute('data-tipi-enabled') === '1';
  function clearTrackerState() {
    var keys = ['ta_visitor_id','ta_session_id','ta_first_touch','ta_current_touch','ta_identity','ta_last_pageview','ta_last_identify','ta_url_revenue_events','ta_element_revenue_events','ta_auto_conversion_events','ta_tracker_rule_config','ta_event_queue'];
    keys.forEach(function (key) {
      try { localStorage.removeItem(key); sessionStorage.removeItem(key); } catch (_) {}
      document.cookie = key + '=; Max-Age=0; Path=/; SameSite=Lax';
      document.cookie = key + '=; Max-Age=0; Path=/; SameSite=Lax; Domain=' + location.hostname;
    });
  }
  function choose(value) {
    if (value !== 'yes') clearTrackerState();
    document.cookie = 'tipi_metrics_consent=' + encodeURIComponent(binding + '.' + value) + '; Path=/; Max-Age=' + (value === 'reset' ? 0 : 15552000) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    location.replace(location.pathname + location.search + location.hash);
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('#tipi-metrics-consent [data-tipi-consent-choice]');
    if (button) choose(button.getAttribute('data-tipi-consent-choice'));
  });
  window.Tipi = window.Tipi || {};
  window.Tipi.track = function (name, meta) {
    if (!enabled || typeof name !== 'string' || !/^[a-z][a-z0-9_.-]{0,63}$/.test(name) || /^(revenue|identify|pageview|heartbeat)$/.test(name)) return false;
    var clean = {};
    Object.keys(meta || {}).slice(0, 15).forEach(function (key) {
      var value = meta[key];
      if (!/^[a-z][a-z0-9_-]{0,39}$/i.test(key) || /email|phone|address|identity|visitor|session|token|password|provider|revenue_kind|event_uid|original_event/i.test(key)) return;
      if (typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value)) || (typeof value === 'string' && /^[a-zA-Z0-9_.-]{1,80}$/.test(value))) clean[key] = value;
    });
    if (Object.prototype.hasOwnProperty.call(clean, 'value')) { clean.revenue_kind = 'estimated'; clean.is_estimated = true; }
    var api = window.TipiAnalitycs = window.TipiAnalitycs || {q: []};
    if (typeof api.event === 'function') api.event(name, clean);
    else if (Array.isArray(api.q) && api.q.length < 50) api.q.push(['event', name, clean]);
    else return false;
    return true;
  };
})();
JS;
}
function tipiMetricsAsset(): void {
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);return;}
    header('Content-Type: application/javascript; charset=utf-8');header('Cache-Control: no-cache');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')echo tipiMetricsJs();
}
final class Tipi {
    // Call only AFTER the business transaction succeeded. No automatic POST-to-conversion mapping.
    public static function track(string $name,array $meta=[],string $reference=''): bool {
        try{
            $path=explode('?',(string)($_SERVER['REQUEST_URI']??''),2)[0];
            if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!preg_match('~^/apps/[a-z0-9][a-z0-9-]{0,70}$~D',$path))return false;
            if(!preg_match('~^[a-z][a-z0-9_.-]{0,63}$~D',$name)||in_array($name,['revenue','identify','pageview','heartbeat'],true))return false;
            $d=dataDir();$h=tipiHubConfig($d);if(!tipiHubConsent($h))return false;
            $visitor=(string)($_COOKIE['ta_visitor_id']??'');$session=(string)($_COOKIE['ta_session_id']??'');
            if(!preg_match('~^[a-zA-Z0-9_-]{8,80}$~D',$visitor))return false;
            if(!preg_match('~^[a-zA-Z0-9_-]{8,80}$~D',$session))$session='';
            $clean=[];
            foreach(array_slice($meta,0,15,true) as $k=>$v){
                if(!is_string($k)||!preg_match('~^[a-z][a-z0-9_-]{0,39}$~iD',$k)||preg_match('~email|phone|address|identity|visitor|session|token|password|provider|revenue_kind|event_uid|original_event~i',$k))continue;
                if(is_bool($v)||is_int($v)||(is_float($v)&&is_finite($v))||(is_string($v)&&preg_match('~^[a-zA-Z0-9_.-]{1,80}$~D',$v)))$clean[$k]=$v;
            }
            if(isset($clean['value'])){$clean['revenue_kind']='estimated';$clean['is_estimated']=true;}
            $clean['name']=$name;$clean['original_event']=$name;$clean['category']='custom';$clean['source']='tipi_cms_php';
            $binding=tipiHubBinding($h);$uid=hash('sha256',$binding.'|'.$name.'|'.($reference!==''?$reference:bin2hex(random_bytes(16))));
            $cfg=jread($d.'/config.json');
            $row=['binding'=>$binding,'payload'=>['event_uid'=>$uid,'event_name'=>'event','visitor_id'=>$visitor,'session_id'=>$session?:null,'ts'=>time(),'url'=>$cfg['url'].$path,'path'=>$path,'meta'=>$clean]];
            $ok=locked($d,function()use($d,$row,$uid,$binding){
                if(!hash_equals($binding,tipiHubBinding(tipiHubConfig($d)))||!tipiHubEnabled(tipiHubConfig($d)))return false;
                $file=$d.'/hub-outbox/'.$uid.'.json';
                if(is_file($file))return true;
                if(count(glob($d.'/hub-outbox/*.json')?:[])>=500)return false;
                jwrite($file,$row);return true;
            });
            if($ok){static $scheduled=false;if(!$scheduled){$scheduled=true;register_shutdown_function(static function()use($d){try{tipiHubFlush($d,1);}catch(Throwable $ex){error_log('Tipi CMS: événement en attente');}});}}
            return $ok;
        }catch(Throwable $ex){error_log('Tipi CMS: événement métier non enregistré');return false;}
    }
}
function tipiHubFlush(string $d,int $limit=10): array {
    $h=tipiHubConfig($d);if(!tipiHubEnabled($h)||!function_exists('curl_init'))return ['sent'=>0,'status'=>'disabled_or_curl_missing'];
    $lock=fopen($d.'/hub-outbox.lock','c');if(!$lock)return ['sent'=>0,'status'=>'busy'];
    $sent=0;
    try{
        if(!flock($lock,LOCK_EX|LOCK_NB))return ['sent'=>0,'status'=>'busy'];
        $files=glob($d.'/hub-outbox/*.json')?:[];
        usort($files,static fn($a,$b)=>filemtime($a)<=>filemtime($b));
        foreach(array_slice($files,0,max(1,min(20,$limit))) as $file){
            if(is_link($file))continue;
            $row=jread($file);$current=tipiHubConfig($d);
            if(!tipiHubEnabled($current))break;
            if(($row['binding']??'')!==tipiHubBinding($current)||($row['payload']['ts']??0)<time()-604800){unlink($file);continue;}
            $payload=$row['payload'];$payload['site_key']=$current['tracker_key'];
            $ch=curl_init(TIPI_ANALYTICS_ORIGIN.'/api/analitycs/collect');
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>600,CURLOPT_TIMEOUT_MS=>1500,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
            $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            $result=is_string($body)?json_decode($body,true):null;
            if($code>=200&&$code<300&&($result['ok']??false)===true&&($result['accepted']??0)>0){unlink($file);$sent++;}
            else break;
        }
        return ['sent'=>$sent,'status'=>'attempted'];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function shell(string $title,string $body,string $head='',string $nav='',string $brand='Tipi CMS',array $designConfig=[],string $template=''): void {
    header('Content-Type: text/html; charset=utf-8');
    $layout=match($designConfig['layout']??'classic'){'wide'=>'header,main,footer{max-width:1200px}','centered'=>'header{justify-content:center;text-align:center}main{max-width:760px}nav{width:100%}','editorial'=>'main{max-width:760px}h1,h2,h3{font-family:Georgia,serif}header{max-width:1100px}',default=>''};
    $extra=$designConfig['custom_css']??'';
    if(!is_string($extra)||preg_match('~[<>]~',$extra))$extra='';
    $style=strtr(css(),array_combine(['#176044','#244d39','#f7f8f5','#182c23'],design($designConfig))).$layout.$extra;
    if($designConfig&&function_exists('tipiTheme')){echo tipiHubRender(tipiTheme(dataDir(),$designConfig,$head,$style,$body,$nav,$brand,$title,$template),$designConfig);return;}
    $html='<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title>'.$head.'<style>'.strtr(css(),array_combine(['#176044','#244d39','#f7f8f5','#182c23'],design($designConfig))).$layout.$extra.'</style></head><body><header><strong><a href="/">'.e($brand).'</a></strong><nav>'.$nav.'</nav></header><main>'.$body.'</main><footer>Propulsé par Tipi CMS · Propulsé par Codegraft™</footer></body></html>';
    echo $designConfig?tipiHubRender($html,$designConfig):$html;
}
function navFor(string $d): string {
    $links=[];
    foreach(inventory($d) as $slug=>$m) if (!str_contains($slug,'/') && $slug!=='home' && isset($m['nav']) && !noindex($m)) $links[]=['slug'=>$slug,'label'=>$m['nav_label']??$m['title'],'order'=>(int)$m['nav']];
    usort($links,fn($a,$b)=>$a['order']<=>$b['order']);
    return implode('',array_map(fn($x)=>'<a href="'.e(pageurl($x['slug'])).'">'.e($x['label']).'</a>',$links));
}
function renderBody(string $d,string $body,array $cfg=[]): string {
    // Only whole-line includes and blog lists; no executable PHP or raw HTML.
    $out=''; $buf=[]; $fenced=false;
    $flush=static function()use(&$out,&$buf){if($buf){$out.=MiniMarkdown::toHtml(implode("\n",$buf));$buf=[];}};
    foreach(explode("\n",$body) as $line) {
        if (preg_match('~^\s*\x60{3}~',$line)) $fenced=!$fenced;
        if (!$fenced && trim($line)==='{{list:blog}}') {
            $flush(); $posts=array_filter(inventory($d),fn($m,$k)=>str_starts_with($k,'blog/')&&!noindex($m),ARRAY_FILTER_USE_BOTH);
            uasort($posts,fn($a,$b)=>strcmp($b['published_at']??$b['date']??'',$a['published_at']??$a['date']??''));
            foreach($posts as $slug=>$m) $out.='<article><h2><a href="'.e(pageurl($slug)).'">'.e($m['title']).'</a></h2><p>'.e($m['description']??'').'</p></article>';
        } elseif (!$fenced && function_exists('tipiMarkdownComponent') && preg_match('~^\s*\{\{component:([A-Za-z0-9_-]+)\}\}\s*$~D',$line,$m)) {
            $flush();$out.=tipiMarkdownComponent($m[1],$cfg);
        } elseif (!$fenced && preg_match('~^\s*\{\{include:partials/([a-z0-9-]+)\.md\}\}\s*$~D',$line,$m)) {
            $flush(); $p=$d.'/partials/'.$m[1].'.md'; if(is_file($p)&&!is_link($p)) $out.=MiniMarkdown::toHtml((string)file_get_contents($p));
        } else $buf[]=$line;
    }
    $flush(); return $out;
}
function publicPage(string $d,array $cfg,string $slug,bool $preview=false): void {
    $raw=$preview?effective($d,$slug):loadDoc($d,$slug,'published');
    if ($raw===null) {http_response_code(404);shell('Page introuvable','<h1>Page introuvable</h1>','<meta name="robots" content="noindex">',navFor($d),$cfg['name']);return;}
    [$m,$body]=front($raw); $title=$m['seo_title']??$m['title']; $desc=$m['description']??'';
    $url=$cfg['url'].pageurl($slug);
    $head='<meta name="description" content="'.e($desc).'"><meta name="robots" content="'.($preview||noindex($m)?'noindex, follow':'index, follow, max-image-preview:large').'"><link rel="canonical" href="'.e($url).'"><meta property="og:title" content="'.e($title).'"><meta property="og:description" content="'.e($desc).'"><meta property="og:url" content="'.e($url).'"><meta property="og:type" content="'.(str_starts_with($slug,'blog/')?'article':'website').'"><meta name="twitter:card" content="summary">';
    $schema=['@context'=>'https://schema.org','@type'=>str_starts_with($slug,'blog/')?'Article':'WebPage','name'=>$m['title'],'url'=>$url,'description'=>$desc];
    if(str_starts_with($slug,'blog/')) { $schema['headline']=$m['title']; if(!empty($m['published_at']))$schema['datePublished']=$m['published_at']; if(!empty($m['modified']))$schema['dateModified']=$m['modified']; if(!empty($m['author']))$schema['author']=['@type'=>'Person','name'=>$m['author']]; }
    $head.='<script type="application/ld+json">'.json_encode($schema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).'</script>';
    $html=renderBody($d,$body,$preview?[]:$cfg);
    if (!preg_match('~<h1>~i',$html)) $html='<h1>'.e($m['title']).'</h1>'.$html;
    if($preview) $html='<p class="panel">Prévisualisation privée</p>'.$html;
    shell($title,$html,$head,navFor($d),$cfg['name'],$preview?[]:$cfg,$m['template']??'');
}
function setup(string $d): void {
    sessionStart();
    $hash=TIPI_SETUP_HASH;
    if($hash==='' && getenv('TIPI_SETUP_KEY')) $hash=hash('sha256',getenv('TIPI_SETUP_KEY'));
    if($hash==='') {http_response_code(403);shell('Installation verrouillée','<h1>Installation verrouillée</h1><p>Générez un installateur personnalisé avec php tipi-install.php --prepare ou configurez TIPI_SETUP_KEY.</p>');return;}
    if($_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        if(!hash_equals($hash,hash('sha256',(string)($_POST['key']??'')))) fail('Clé d’installation incorrecte',403);
        $pw=(string)($_POST['password']??''); if(strlen($pw)<12 || strlen($pw)>200) fail('Mot de passe : 12 à 200 caractères');
        $name=trim((string)($_POST['name']??'')); if($name===''||strlen($name)>120||preg_match('~[\r\n\x00]~',$name))fail('Nom de site invalide');
        $url=siteurl(trim((string)($_POST['url']??'')));
        if (basename(__FILE__)!=='index.php' && file_exists(__DIR__.'/index.php')) fail('index.php existe déjà : utilisez un dossier vide',409);
        if (file_exists(__DIR__.'/.htaccess')) fail('.htaccess existe déjà : utilisez un dossier vide',409);
        if(!is_dir($d)&&!mkdir($d,0700,true)&&!is_dir($d))fail('Impossible de créer le stockage privé',500);
        locked($d,function()use($d,$name,$url,$pw){
            if(is_file($d.'/config.json'))fail('Déjà installé',409);
            atomic($d.'/.htaccess',"Require all denied\n");
            $seed=['home'=>rawDoc($name,"# ".$name."\n\nBienvenue sur votre nouveau site. Modifiez cette page dans l’administration.\n\n## Derniers articles\n\n{{list:blog}}\n"),'blog'=>rawDoc('Blog',"# Blog\n\n{{list:blog}}\n",['nav'=>'10']),'blog/bienvenue'=>rawDoc('Bienvenue',"# Bienvenue\n\nVotre premier article est prêt. Vous pouvez le modifier depuis le panneau d’administration.\n",['description'=>'Découvrez votre nouveau site.','author'=>'Équipe éditoriale'])];
            foreach($seed as $slug=>$raw){[$m,$b]=front($raw);$m['id']=bin2hex(random_bytes(12));$m['published_at']=gmdate(DATE_ATOM);atomic(fileDoc($d,$slug,'published'),encodeDoc($m,$b));}
            $source=(string)file_get_contents(__FILE__);
            $source=preg_replace("~const TIPI_SETUP_HASH = '[a-f0-9]*';~","const TIPI_SETUP_HASH = '';",$source,1);
            $createdIndex=false; $createdHtaccess=false;
            try {
            if(basename(__FILE__)!=='index.php') {
                if(file_exists(__DIR__.'/index.php')) fail('index.php existe déjà',409);
                atomic(__DIR__.'/index.php',$source); $createdIndex=true; chmod(__DIR__.'/index.php',0644);
            }
            if(file_exists(__DIR__.'/.htaccess')) fail('.htaccess existe déjà',409);
            atomic(__DIR__.'/.htaccess',"Options -Indexes\nDirectoryIndex index.php\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteRule ^index\.php$ - [L]\nRewriteRule ^tipi-install[^/]*\.php$ - [F,L]\nRewriteRule ^ index.php [L,QSA]\n");
            $createdHtaccess=true; chmod(__DIR__.'/.htaccess',0644);
            jwrite($d.'/config.json',['name'=>$name,'url'=>$url,'password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'api_hash'=>'','api_publish'=>false,'version'=>TIPI_VERSION]);
            } catch(Throwable $error) {
                if($createdHtaccess) @unlink(__DIR__.'/.htaccess');
                if($createdIndex) @unlink(__DIR__.'/index.php');
                throw $error;
            }
        });
        if(basename(__FILE__)!=='index.php') @unlink(__FILE__);
        redirect('/admin');
    }
    header('Cache-Control: no-store');
    shell('Installer Tipi CMS','<div class="panel"><h1>Installer Tipi CMS</h1><p>Markdown, PHP, sans base de données.</p><form method="post">'.csrf().field('key','Clé d’installation','','password').field('name','Nom du site').field('url','URL du site','https://').field('password','Mot de passe administrateur (12 caractères minimum)','','password').'<button>Installer</button></form></div>');
}
function admin(string $d,array $cfg): void {
    sessionStart(); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
    $post=$_SERVER['REQUEST_METHOD']==='POST';
    if(empty($_SESSION['admin'])) {
        if($post){verifyCsrf(); if(password_verify((string)($_POST['password']??''),$cfg['password_hash'])){session_regenerate_id(true);$_SESSION['admin']=true;redirect('/admin');}usleep(400000);fail('Identifiants incorrects',403);}
        shell('Administration','<div class="panel"><h1>Administration</h1><form method="post">'.csrf().field('password','Mot de passe','','password').'<button>Connexion</button></form></div>');return;
    }
    if($post) {
        verifyCsrf(); $act=(string)($_POST['action']??'');
        if($act==='logout'){$_SESSION=[];session_destroy();redirect('/admin');}
        if(in_array($act,['ai_profile','ai_approval_mode','ai_deploy','ai_rollback','ai_revoke','ai_approve_module','ai_enable_contacts'],true)){
            $bridge=dirname(__DIR__).'/tipi-system/bridge.php';
            if(!is_file($bridge)||is_link($bridge))fail('Assistant IA non installé',409);
            require_once $bridge;
            tipiAdminAction($d,$cfg,$act,$_POST);redirect('/admin?connections=1');
        }
        if(in_array($act,['hub_save','hub_disconnect','hub_flush'],true)){
            if($act==='hub_flush')tipiHubFlush($d);else tipiHubSettings($d,$_POST);
            redirect('/admin?hub=1');
        }
        if($act==='design_restore'){
            $id=(string)($_POST['revision']??'');
            if(!preg_match('~^\d{14}-[a-f0-9]{12}$~D',$id))fail('Version invalide');
            $old=jread($d.'/design-history/'.$id.'.json');if(!$old)fail('Version introuvable',404);
            changeDesign($d,$old,(string)($_POST['expected']??''));redirect('/admin?settings=1');
        }
        if($act==='settings'){
            $values=['name'=>trim((string)($_POST['name']??'')),'theme'=>(string)($_POST['theme']??'forest'),'accent'=>trim((string)($_POST['accent']??'')),'layout'=>(string)($_POST['layout']??'classic'),'custom_css'=>(string)($_POST['custom_css']??'')];
            changeDesign($d,$values,(string)($_POST['expected']??''));
            redirect('/admin?settings=1');
        }
        if($act==='business_policy'){
            $bridge=dirname(__DIR__).'/tipi-system/bridge.php';
            if(!is_file($bridge)||is_link($bridge))fail('CodeGraft non installé',409);
            require_once $bridge;
            locked($d,function()use($d){$c=jread($d.'/config.json');$c['business_read']=isset($_POST['business_read']);$c['business_write']=$c['business_read']&&isset($_POST['business_write']);
                if(isset($_POST['business_approve'])){
                    $manifest=tipiBusinessManifest(dirname(__DIR__));
                    $fingerprint=hash('sha256',json_encode($manifest,JSON_THROW_ON_ERROR));
                    if(!hash_equals($fingerprint,(string)($_POST['business_fingerprint']??'')))fail('Les modules ont changé : rechargez et vérifiez les nouvelles versions',409);
                    $c['business_approved']=$manifest;
                }
                if(isset($_POST['business_clear'])){$c['business_approved']=[];$c['business_read']=false;$c['business_write']=false;}
                jwrite($d.'/config.json',$c);});
            redirect('/admin?connections=1');
        }
        if($act==='mcp_rotate'){
            $token=bin2hex(random_bytes(32));
            locked($d,function()use($d,$token){$c=jread($d.'/config.json');$c['mcp_hash']=hash('sha256',$token);$c['mcp_write']=false;$c['mcp_publish']=false;jwrite($d.'/config.json',$c);});
            shell('Clé MCP','<h1>Clé MCP</h1><p>Copiez cette clé privée, affichée une seule fois. Lecture seule par défaut.</p><pre>'.e($token).'</pre><a href="/admin?connections=1">Retour</a>');return;
        }
        if($act==='mcp_revoke'||$act==='mcp_policy'){
            locked($d,function()use($d,$act){$c=jread($d.'/config.json');
                if($act==='mcp_revoke'){$c['mcp_hash']='';$c['mcp_write']=false;$c['mcp_publish']=false;}
                else{$c['mcp_write']=isset($_POST['mcp_write']);$c['mcp_publish']=$c['mcp_write']&&isset($_POST['mcp_publish']);}
                jwrite($d.'/config.json',$c);});
            redirect('/admin?connections=1');
        }
        if($act==='rotate') {
            $token=bin2hex(random_bytes(32));
            locked($d,function()use($d,$token){$c=jread($d.'/config.json');$c['api_hash']=hash('sha256',$token);$c['api_publish']=false;jwrite($d.'/config.json',$c);});
            shell('Clé API','<h1>Clé API éditoriale</h1><p>Copiez cette clé maintenant. Elle ne sera plus affichée.</p><pre>'.e($token).'</pre><a href="/admin">Retour</a>');return;
        }
        if($act==='revoke'||$act==='api_policy'){
            locked($d,function()use($d,$act){$c=jread($d.'/config.json');if($act==='revoke'){$c['api_hash']='';$c['api_publish']=false;}else $c['api_publish']=isset($_POST['allow_publish']);jwrite($d.'/config.json',$c);});
            redirect('/admin');
        }
        $slug=slugok((string)($_POST['slug']??''));$expected=(string)($_POST['expected']??'');
        if($act==='trash'){
            trashDocuments($d,[['slug'=>$slug,'state_sha256'=>$expected]]);redirect('/admin?trash=1');
        }
        if($act==='restore') restoreDoc($d,$slug,(string)($_POST['revision']??''),$expected);
        elseif(in_array($act,['draft','publish'],true))saveDoc($d,$slug,(string)($_POST['raw']??''),$expected,$act==='publish');
        else fail('Action inconnue');
        redirect('/admin?edit='.rawurlencode($slug));
    }
    $nav='<a href="/admin">Contenus</a><a href="/admin?settings=1">Apparence</a><a href="/admin?connections=1">Assistant IA</a><a href="/admin?hub=1">Le Tipi</a><a href="/">Voir le site</a><form method="post" action="/admin" style="display:inline">'.csrf().'<button class="secondary" name="action" value="logout">Déconnexion</button></form>';
    if(function_exists('tipiAppNav'))$nav.=tipiAppNav(dirname(__DIR__),$cfg,true);
    if(isset($_GET['hub'])){shell('Le Tipi',tipiHubPanel($d),'',$nav);return;}
    if(isset($_GET['settings'])){
        $choices='';foreach(['forest'=>'Forêt','ink'=>'Encre','sand'=>'Sable'] as $k=>$label)$choices.='<option value="'.$k.'" '.(($cfg['theme']??'forest')===$k?'selected':'').'>'.$label.'</option>';
        $layouts='';foreach(['classic'=>'Classique','wide'=>'Large','centered'=>'Centré','editorial'=>'Éditorial'] as $k=>$label)$layouts.='<option value="'.$k.'" '.(($cfg['layout']??'classic')===$k?'selected':'').'>'.$label.'</option>';
        $body='<h1>Apparence du site</h1><form method="post">'.csrf().'<input type="hidden" name="expected" value="'.designHash($cfg).'">'.field('name','Nom du site',$cfg['name']).'<label>Palette<select name="theme">'.$choices.'</select></label><label>Disposition<select name="layout">'.$layouts.'</select></label><label>Couleur personnalisée<input name="accent" value="'.e($cfg['accent']??'').'"></label><label>CSS personnalisé<textarea name="custom_css">'.e($cfg['custom_css']??'').'</textarea></label><button name="action" value="settings">Enregistrer</button></form><h2>Historique du design</h2>';
        $history=glob($d.'/design-history/*.json')?:[];rsort($history);
        foreach(array_slice($history,0,10) as $f){$id=basename($f,'.json');$body.='<form method="post">'.csrf().'<input type="hidden" name="expected" value="'.designHash($cfg).'"><input type="hidden" name="revision" value="'.e($id).'"><button class="secondary" name="action" value="design_restore">Restaurer '.e($id).'</button></form>';}
        shell('Apparence',$body,'',$nav);return;
    }
    if(isset($_GET['connections'])){
        $system=dirname(__DIR__).'/tipi-system';
        $body='<h1>Assistant IA</h1>';
        if(is_file($system.'/bridge.php')&&is_file($system.'/codegraft/standalone/config.php')){
            require_once $system.'/bridge.php';$body.=tipiAdminPanel($d,$cfg);
        }else $body.='<p>Assistant de développement non installé. Voir la procédure de la distribution.</p>';
        $body.='<details><summary>Connexions techniques avancées</summary><h2>API éditoriale</h2><p>Endpoint : <code>'.e($cfg['url'].'/api/v1').'</code>. Les clés se gèrent dans Contenus.</p><p>Le Tipi : association au Hub à venir.</p></details>';
        shell('Assistant IA',$body,'',$nav);return;
    }
    if(isset($_GET['trash'])){
        $body='<h1>Corbeille</h1><p>Pages et articles supprimés, brouillons compris. <a href="/admin">Retour aux contenus</a></p>';
        foreach(trashList($d) as $doc)$body.='<div class="panel"><strong>'.e($doc['title']).'</strong><p>'.e($doc['slug']).' · '.e($doc['deleted_at']).'</p><form method="post">'.csrf().'<input type="hidden" name="slug" value="'.e($doc['slug']).'"><input type="hidden" name="expected" value=""><input type="hidden" name="revision" value="'.e($doc['revision']).'"><button name="action" value="restore">Restaurer</button></form></div>';
        shell('Corbeille',$body,'',$nav);return;
    }
    if(isset($_GET['preview'])){publicPage($d,$cfg,slugok((string)$_GET['preview']),true);return;}
    if(isset($_GET['edit'])||isset($_GET['new'])){
        $slug=isset($_GET['edit'])?slugok((string)$_GET['edit']):'';$raw=$slug!==''?effective($d,$slug):null;
        $rawForm=$raw??rawDoc('Nouvelle page',"\n# Nouvelle page\n\n");
        $body='<h1>'.($slug===''?'Nouvelle page':e($slug)).'</h1><form method="post">'.csrf().'<input type="hidden" name="expected" value="'.sha($raw).'">'.field('slug','Identifiant (ex. services ou blog/article)',$slug).'<label>Markdown<textarea name="raw" required>'.e($rawForm).'</textarea></label><button name="action" value="draft" class="secondary">Enregistrer le brouillon</button><button name="action" value="publish">Publier</button></form>';
        if($slug!==''&&$raw!==null){
            $body.='<form method="post">'.csrf().'<input type="hidden" name="slug" value="'.e($slug).'"><input type="hidden" name="expected" value="'.documentState($d,$slug).'"><p>Déplacer cette page et ses brouillons vers la corbeille (restaurable).</p><button class="secondary" name="action" value="trash">Mettre à la corbeille</button></form>';
            $body.='<p><a href="/admin?preview='.rawurlencode($slug).'">Prévisualiser</a></p><h2>Historique</h2>';
            $files=glob($d.'/history/'.$slug.'/*.json')?:[];rsort($files);
            foreach(array_slice($files,0,20) as $f){$rev=basename($f,'.json');$body.='<form method="post">'.csrf().'<input type="hidden" name="slug" value="'.e($slug).'"><input type="hidden" name="expected" value="'.sha($raw).'"><input type="hidden" name="revision" value="'.e($rev).'"><button class="secondary" name="action" value="restore">Restaurer '.e($rev).'</button></form>';}
        }
        shell('Édition',$body,'',$nav);return;
    }
    $live=inventory($d);$drafts=inventory($d,'drafts');$all=array_replace($live,$drafts);ksort($all);
    $body='<h1>Vos contenus</h1><p><a class="btn" href="/admin?new=1">Nouvelle page ou article</a> <a href="/admin?trash=1">Corbeille</a></p><table><tr><th>Page</th><th>État</th><th></th></tr>';
    foreach($all as $slug=>$m)$body.='<tr><td>'.e($m['title']).'<br><small>'.e(pageurl($slug)).'</small></td><td>'.(isset($drafts[$slug])?'Brouillon'.(isset($live[$slug])?' + publié':''):'Publié').'</td><td><a href="/admin?edit='.rawurlencode($slug).'">Modifier</a></td></tr>';
    $body.='</table><h2>API éditoriale</h2><p>Clé '.(empty($cfg['api_hash'])?'inactive':'active').'. Aucun accès au PHP.</p><form method="post">'.csrf().'<button name="action" value="rotate">Générer / renouveler la clé</button><button class="secondary" name="action" value="revoke">Révoquer</button></form><form method="post">'.csrf().'<label><input style="display:inline;width:auto" type="checkbox" name="allow_publish" '.(!empty($cfg['api_publish'])?'checked':'').'> Autoriser la publication via API</label><button name="action" value="api_policy">Enregistrer</button></form><form method="post">'.csrf().'<button class="secondary" name="action" value="logout">Déconnexion</button></form>';
    shell('Administration',$body,'',$nav);
}
function api(string $d,array $cfg,string $path): never {
    $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(empty($cfg['api_hash'])||!preg_match('~^Bearer ([a-f0-9]{64})$~D',$auth,$m)||!hash_equals($cfg['api_hash'],hash('sha256',$m[1])))jsonout(['error'=>'Non autorisé'],401);
    $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'&&$path==='/api/v1/capabilities')jsonout(['version'=>TIPI_VERSION,'storage'=>'markdown','publish'=>!empty($cfg['api_publish']),'mcp'=>true,'mcp_auth'=>'bearer','codegraft_bridge'=>is_file(dirname(__DIR__).'/tipi-system/bridge.php'),'hub'=>tipiHubStatus($d)]);
    if($method==='GET'&&$path==='/api/v1/pages'){
        $live=inventory($d);$draft=inventory($d,'drafts');$out=[];
        foreach(array_replace($live,$draft) as $slug=>$m)$out[]=['slug'=>$slug,'title'=>$m['title'],'published'=>isset($live[$slug]),'draft'=>isset($draft[$slug]),'sha256'=>sha(effective($d,$slug)),'state_sha256'=>documentState($d,$slug)];
        jsonout(['pages'=>$out]);
    }
    if(!preg_match('~^/api/v1/pages/(.+)$~D',$path,$m))jsonout(['error'=>'Route inconnue'],404);
    $slug=slugok($m[1]);
    if($method==='GET'){$raw=effective($d,$slug);if($raw===null)jsonout(['error'=>'Introuvable'],404);jsonout(['slug'=>$slug,'raw'=>$raw,'sha256'=>sha($raw),'state_sha256'=>documentState($d,$slug),'published'=>loadDoc($d,$slug,'published')!==null,'draft'=>loadDoc($d,$slug,'drafts')!==null]);}
    if($method==='PUT'){
        $body=file_get_contents('php://input',false,null,0,MAX_CONTENT+4096);
        $j=json_decode((string)$body,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($j)||!isset($j['raw'],$j['expected_sha256'])||!is_string($j['raw'])||!is_string($j['expected_sha256']))fail('raw et expected_sha256 requis');
        $publish=($j['publish']??false)===true;
        if($publish&&empty($cfg['api_publish']))fail('Publication API désactivée',403);
        jsonout(saveDoc($d,$slug,$j['raw'],$j['expected_sha256'],$publish));
    }
    jsonout(['error'=>'Méthode non autorisée'],405);
}
function mcpTools(): array {
    $empty=['type'=>'object','properties'=>(object)[],'additionalProperties'=>false];
    $input=static fn(array $p,array $r)=>['type'=>'object','properties'=>$p,'required'=>$r,'additionalProperties'=>false];
    $slug=['type'=>'string','description'=>'Identifiant : home, services ou blog/article'];
    $hash=['type'=>'string','description'=>'Empreinte SHA-256 actuelle ; chaîne vide pour un nouveau document'];
    $raw=['type'=>'string','description'=>'Document Markdown complet, front matter inclus'];
    $tool=static fn($n,$d,$schema,$ro)=>['name'=>$n,'description'=>$d,'inputSchema'=>$schema,'annotations'=>['readOnlyHint'=>$ro,'destructiveHint'=>!$ro,'openWorldHint'=>false]];
    return [
        $tool('cms_site_info','Informations et capacités du site : contenu, design et extensions métier. À consulter avant de proposer une intervention.',$empty,true),
        $tool('cms_tracking_info','Lit le contrat de tracking natif, état local et conventions HTML/JS/PHP. Ne retourne aucune clé.',$empty,true),
        $tool('cms_get_design','Lire palette, disposition, CSS personnalisé et empreinte',$empty,true),
        $tool('cms_update_design','Modifier le design public (palette, disposition, CSS responsive) sans toucher au noyau. Utiliser le SHA de cms_get_design.',$input(['expected_sha256'=>$hash,'name'=>['type'=>'string'],'theme'=>['type'=>'string','enum'=>['forest','ink','sand']],'accent'=>['type'=>'string'],'layout'=>['type'=>'string','enum'=>['classic','wide','centered','editorial']],'custom_css'=>['type'=>'string']],['expected_sha256']),false),
        $tool('cms_list_pages','Liste les documents avec leur état et empreinte',$empty,true),
        $tool('cms_read_page','Lit le brouillon ou le document publié',$input(['slug'=>$slug],['slug']),true),
        $tool('cms_save_draft','Crée ou modifie un brouillon privé. Requiert écriture.',$input(['slug'=>$slug,'raw'=>$raw,'expected_sha256'=>$hash],['slug','raw','expected_sha256']),false),
        $tool('cms_list_trash','Liste les documents supprimés et leurs révisions restaurables',$empty,true),
        $tool('cms_delete_page','Met à la corbeille une page ou un article et ses brouillons. Demander accord explicite avant suppression. Utiliser state_sha256 de cms_list_pages.',$input(['slug'=>$slug,'state_sha256'=>$hash],['slug','state_sha256']),false),
        $tool('cms_delete_pages','Met à la corbeille jusqu’à 100 documents explicitement sélectionnés. Présenter la liste et obtenir accord avant appel. Les empreintes des deux états sont vérifiées.',$input(['pages'=>['type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>$input(['slug'=>$slug,'state_sha256'=>$hash],['slug','state_sha256'])]],['pages']),false),
        $tool('cms_restore_page','Restaure une révision depuis la corbeille uniquement si le slug est toujours libre.',$input(['slug'=>$slug,'revision'=>['type'=>'string']],['slug','revision']),false),
        $tool('cms_publish','Publie un document Markdown complet. Requiert publication.',$input(['slug'=>$slug,'raw'=>$raw,'expected_sha256'=>$hash],['slug','raw','expected_sha256']),false)
    ];
}
function mcpCall(string $d,array $cfg,string $name,array $args): array {
    if($name==='cms_tracking_info')return tipiHubStatus($d);
    if($name==='cms_get_design')return ['design'=>siteDesign($cfg),'sha256'=>designHash($cfg)];
    if($name==='cms_update_design'){
        if(empty($cfg['mcp_write'])||empty($cfg['mcp_publish']))fail('Modification publique désactivée',403);
        if(!is_string($args['expected_sha256']??null))fail('expected_sha256 requis');
        $expected=$args['expected_sha256'];unset($args['expected_sha256']);return changeDesign($d,$args,$expected);
    }
    if($name==='cms_site_info')return ['name'=>$cfg['name'],'url'=>$cfg['url'],'version'=>TIPI_VERSION,'storage'=>'markdown','draft_write'=>!empty($cfg['mcp_write']),'publish'=>!empty($cfg['mcp_publish']),'ai_profile'=>$cfg['ai_profile']??'read','design'=>['read'=>true,'write'=>!empty($cfg['mcp_write'])&&!empty($cfg['mcp_publish']),'layouts'=>['classic','wide','centered','editorial'],'custom_css'=>true],'business'=>['read'=>!empty($cfg['business_read']),'write'=>!empty($cfg['business_write']),'development'=>($cfg['ai_profile']??'read')==='build','guide'=>'Utiliser cms_developer_guide : créer des routes, vues, contrôleurs, services, actions MCP, layouts HTML, composants et assets CSS/JS, puis préparer et vérifier un patch avec cg_review_release. Respecter le mode de validation sélectionné.']];
    if($name==='cms_list_pages'){
        $live=inventory($d);$draft=inventory($d,'drafts');$out=[];
        foreach(array_replace($live,$draft) as $slug=>$m)$out[]=['slug'=>$slug,'title'=>$m['title'],'published'=>isset($live[$slug]),'draft'=>isset($draft[$slug]),'sha256'=>sha(effective($d,$slug)),'state_sha256'=>documentState($d,$slug)];
        return ['pages'=>$out];
    }
    if($name==='cms_list_trash')return ['documents'=>trashList($d)];
    if(in_array($name,['cms_delete_page','cms_delete_pages','cms_restore_page'],true)){
        if(empty($cfg['mcp_write'])||empty($cfg['mcp_publish']))fail('Permission de publication et suppression requise',403);
        if($name==='cms_delete_page')return trashDocuments($d,[$args]);
        if($name==='cms_delete_pages'){
            if(!is_array($args['pages']??null))fail('Liste de documents requise');
            return trashDocuments($d,$args['pages']);
        }
        if(!is_string($args['slug']??null)||!is_string($args['revision']??null))fail('Slug et révision requis');
        restoreDoc($d,$args['slug'],$args['revision'],'');
        return ['status'=>'restored','slug'=>$args['slug'],'url'=>pageurl($args['slug'])];
    }
    if(!in_array($name,['cms_read_page','cms_save_draft','cms_publish'],true))fail('Outil inconnu',404);
    if(!isset($args['slug'])||!is_string($args['slug']))fail('slug requis');
    $slug=slugok($args['slug']);
    if($name==='cms_read_page'){
        $raw=effective($d,$slug);if($raw===null)fail('Introuvable',404);
        return ['slug'=>$slug,'raw'=>$raw,'sha256'=>sha($raw),'state_sha256'=>documentState($d,$slug),'published'=>loadDoc($d,$slug,'published')!==null,'draft'=>loadDoc($d,$slug,'drafts')!==null];
    }
    if(empty($cfg['mcp_write'])||($name==='cms_publish'&&empty($cfg['mcp_publish'])))fail('Permission MCP désactivée',403);
    if(!isset($args['raw'],$args['expected_sha256'])||!is_string($args['raw'])||!is_string($args['expected_sha256']))fail('raw et expected_sha256 requis');
    return saveDoc($d,$slug,$args['raw'],$args['expected_sha256'],$name==='cms_publish');
}
function mcp(string $d,array $cfg): never {
    header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if($origin!==''&&$origin!==$cfg['url'])jsonout(['error'=>'Origin non autorisée'],403);
    $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(empty($cfg['mcp_hash'])||!preg_match('~^Bearer ([a-f0-9]{64})$~D',$auth,$m)||!hash_equals($cfg['mcp_hash'],hash('sha256',$m[1]))){
        header('WWW-Authenticate: Bearer realm="Tipi CMS MCP"');jsonout(['error'=>'Non autorisé'],401);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');jsonout(['error'=>'Méthode non autorisée'],405);}
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))jsonout(['error'=>'Content-Type application/json requis'],415);
    $id=null;$err=static function(int $code,string $msg)use(&$id):never{jsonout(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$msg]]);};
    try{
        $bytes=file_get_contents('php://input',false,null,0,MAX_CONTENT*6+8192);
        $j=json_decode((string)$bytes,true,32,JSON_THROW_ON_ERROR);
    }catch(JsonException $ex){$err(-32700,'JSON invalide');}
    if(!is_array($j)||($j['jsonrpc']??null)!=='2.0'||!is_string($j['method']??null))$err(-32600,'Requête invalide');
    $id=$j['id']??null;$method=$j['method'];$p=$j['params']??[];
    if(!is_array($p)||($id!==null&&!is_int($id)&&!is_string($id)))$err(-32600,'Requête invalide');
    if(!array_key_exists('id',$j)){http_response_code(202);exit;}
    if($method==='initialize'){
        $v=$p['protocolVersion']??'2025-11-25';
        $result=['protocolVersion'=>in_array($v,['2025-11-25','2025-06-18'],true)?$v:'2025-11-25','capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'tipi-cms','version'=>TIPI_VERSION]];
    }elseif($method==='ping')$result=(object)[];
    elseif($method==='tools/list')$result=['tools'=>mcpTools()];
    elseif($method==='resources/list')$result=['resources'=>[]];
    elseif($method==='prompts/list')$result=['prompts'=>[]];
    elseif($method==='tools/call'){
        if(!is_string($p['name']??null)||!is_array($p['arguments']??[]))$err(-32602,'Paramètres invalides');
        try{$data=mcpCall($d,$cfg,$p['name'],$p['arguments']??[]);$result=['content'=>[['type'=>'text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]]];}
        catch(RuntimeException $ex){$result=['isError'=>true,'content'=>[['type'=>'text','text'=>$ex->getCode()>=500?'Erreur interne':$ex->getMessage()]]];}
    }else $err(-32601,'Méthode inconnue');
    jsonout(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
}
function main(): void {
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https:; frame-src https://www.youtube-nocookie.com; script-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    $request=(string)($_SERVER['REQUEST_URI']??'/');
    if (!str_starts_with($request,'/') || str_starts_with($request,'//')) fail('URL invalide',400);
    $path=explode('?', $request, 2)[0];
    if (!preg_match('~^/[a-zA-Z0-9_./%-]*$~D',$path) || str_contains($path,'//')) fail('URL invalide',400);
    $d=dataDir();$cfg=jread($d.'/config.json');
    if(!$cfg){setup($d);return;}
    $bridge=dirname(__DIR__).'/tipi-system/bridge.php';
    $installed=is_file($bridge)&&!is_link($bridge)&&is_file(dirname(__DIR__).'/tipi-system/codegraft/standalone/config.php');
    if($installed)require_once $bridge;
    // Preserve explicitly configured legacy editorial keys without routing OAuth tokens to the legacy handler.
    if($installed&&in_array($path,['/mcp','/mcp/'],true)){
        $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
        if(!empty($cfg['mcp_hash'])&&preg_match('~^Bearer ([a-f0-9]{64})$~D',$auth,$m)&&hash_equals($cfg['mcp_hash'],hash('sha256',$m[1])))mcp($d,$cfg);
        tipiCodeGraftRoute($path,$d,$cfg);return;
    }
    if(in_array($path,['/mcp/oauth/register/','/mcp/oauth/authorize/','/mcp/oauth/token/','/mcp/oauth/register','/mcp/oauth/authorize','/mcp/oauth/token','/.well-known/oauth-protected-resource/mcp','/.well-known/oauth-protected-resource/mcp/','/.well-known/oauth-authorization-server','/.well-known/oauth-authorization-server/','/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/'],true)){
        $bridge=dirname(__DIR__).'/tipi-system/bridge.php';
        if(!is_file($bridge)||is_link($bridge)||!is_file(dirname(__DIR__).'/tipi-system/codegraft/standalone/config.php')){http_response_code(404);return;}
        require_once $bridge; tipiCodeGraftRoute($path,$d,$cfg);return;
    }
    if($path==='/tipi-metrics.js'){tipiMetricsAsset();return;}
    if($installed&&str_starts_with($path,'/assets/')&&tipiAsset($path,$cfg))return;
    if($installed&&(str_starts_with($path,'/apps/')||str_starts_with($path,'/admin/apps/'))&&tipiAppRoute($path,$d,$cfg))return;
    if(str_starts_with($path,'/tipi-install')||preg_match('~^/codegraft(?:-api)?(?:/|$)~D',$path)){http_response_code(404);return;}
    if($path==='/admin'||$path==='/admin/') {admin($d,$cfg);return;}
    if(str_starts_with($path,'/api/'))api($d,$cfg,$path);
    if($path==='/mcp'||$path==='/mcp/')mcp($d,$cfg);
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);return;}
    if($path==='/robots.txt'){header('Content-Type: text/plain; charset=utf-8');echo "User-agent: *\nDisallow: /admin\nDisallow: /api/\nDisallow: /mcp\nSitemap: ".$cfg['url']."/sitemap.xml\n";return;}
    if($path==='/sitemap.xml'){header('Content-Type: application/xml; charset=utf-8');echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach(inventory($d)as $slug=>$m)if(!noindex($m)){echo '<url><loc>'.e($cfg['url'].pageurl($slug)).'</loc>';if(!empty($m['modified'])&&strtotime($m['modified'])!==false)echo '<lastmod>'.gmdate('Y-m-d',strtotime($m['modified'])).'</lastmod>';echo '</url>';}echo '</urlset>';return;}
    if($path==='/home'){header('Location: /',true,301);return;}
    if($path!=='/'&&str_ends_with($path,'/')&&preg_match('~^/(?:blog/)?[a-z0-9][a-z0-9-]{0,99}/$~D',$path)){header('Location: '.rtrim($path,'/'),true,301);return;}
    $slug=$path==='/'?'home':ltrim($path,'/');
    if(!preg_match('~^(?:blog/)?[a-z0-9][a-z0-9-]{0,99}$~D',$slug)||in_array($slug,['admin','api','assets','robots','sitemap','404','mcp','codegraft','codegraft-api'],true)){http_response_code(404);shell('Page introuvable','<h1>Page introuvable</h1>','<meta name="robots" content="noindex">');return;}
    publicPage($d,$cfg,$slug);
}
if (PHP_SAPI === 'cli' && defined('TIPI_LIBRARY_ONLY') && TIPI_LIBRARY_ONLY===true) return;
if (PHP_SAPI === 'cli') {
    if(($argv[1]??'')==='--tipi-flush-events'){
        echo json_encode(tipiHubFlush(dataDir(),20),JSON_THROW_ON_ERROR).PHP_EOL;exit;
    }
    if(($argv[1]??'')==='--prepare'){
        $key=bin2hex(random_bytes(24));$src=(string)file_get_contents(__FILE__);
        $src=preg_replace("~const TIPI_SETUP_HASH = '[a-f0-9]*';~","const TIPI_SETUP_HASH = '".hash('sha256',$key)."';",$src,1);
        $dest=getcwd().'/tipi-install-ready.php';if(file_exists($dest)){fwrite(STDERR,"Le fichier existe déjà.\n");exit(1);}atomic($dest,$src);
        echo "Fichier : ".$dest."\nClé d’installation : ".$key."\n";exit;
    }
    echo "Tipi CMS ".TIPI_VERSION." : php tipi-install.php --prepare\n";exit;
}
try { main(); } catch(Throwable $ex) {
    $status=$ex instanceof RuntimeException && $ex->getCode()>=400&&$ex->getCode()<600?$ex->getCode():500;
    if($ex instanceof JsonException)$status=400;
    $msg=$status>=500?'Erreur interne. Consultez le journal PHP.':($ex instanceof JsonException?'JSON invalide':$ex->getMessage());
    if($status>=500)error_log('Tipi CMS: '.$ex->getMessage());
    if(str_starts_with($_SERVER['REQUEST_URI']??'','/api/')||str_starts_with($_SERVER['REQUEST_URI']??'','/mcp'))jsonout(['error'=>$msg],$status);
    http_response_code($status);shell('Erreur','<h1>Erreur</h1><p>'.e($msg).'</p><a href="/admin">Retour</a>','<meta name="robots" content="noindex">');
}
