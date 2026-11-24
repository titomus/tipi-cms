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

const TIPI_VERSION = '0.9.0-preview';
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
// An atomic JSON record keeps metadata and canonical HTML in one revision.
// Legacy Markdown files remain readable until explicitly migrated.
function fileDoc(string $d,string $slug,string $state): string { return $d.'/'.$state.'/'.slugok($slug).'.json'; }
function legacyDoc(string $d,string $slug,string $state): string {return $d.'/'.$state.'/'.slugok($slug).'.md';}
function documentSlugs(string $d,string $state): array {
    $out=[];
    foreach(['','blog/'] as $sub){
        $dir=$d.'/'.$state.'/'.$sub;
        if(!is_dir($dir))continue;
        if(!is_readable($dir))fail('Inventaire indisponible',503);
        foreach(['md','json'] as $ext){
            $files=glob($dir.'*.'.$ext);if($files===false)fail('Inventaire indisponible',503);
            foreach($files as $f){if(is_link($f))fail('Document symbolique interdit',409);$out[slugok($sub.basename($f,'.'.$ext))]=true;}
        }
    }
    $slugs=array_keys($out);sort($slugs);return $slugs;
}
function loadDoc(string $d,string $slug,string $state): ?string {
    $f=fileDoc($d,$slug,$state);
    if(is_file($f)){
        if(is_link($f))fail('Document symbolique interdit',409);
        $record=jread($f);
        if(($record['format']??'')!=='html'||!is_array($record['meta']??null)||!is_string($record['html']??null))fail('Document HTML invalide',500);
        return encodeDoc($record['meta']+['format'=>'html'],$record['html']);
    }
    $f=legacyDoc($d,$slug,$state);
    if(is_link($f))fail('Document symbolique interdit',409);
    return is_file($f)?(string)file_get_contents($f):null;
}
function effective(string $d,string $slug): ?string { return loadDoc($d,$slug,'drafts') ?? loadDoc($d,$slug,'published'); }
function sha(?string $raw): string { return $raw===null?'':hash('sha256',$raw); }
function inventory(string $d,string $state='published'): array {
    $out=[];foreach(documentSlugs($d,$state) as $slug){[$meta]=front(loadDoc($d,$slug,$state));$out[$slug]=$meta;}return $out;
}
function dynamicHtml(string $body): bool {
    return str_contains($body,'{{')||str_contains($body,'<!--tipi:');
}
function markdownHtml(string $body): string {
    // Keep only the existing explicit whole-line component syntax executable.
    $out='';$buf=[];$fenced=false;
    $flush=static function()use(&$out,&$buf){if($buf){$out.=MiniMarkdown::toHtml(implode("\n",$buf));$buf=[];}};
    foreach(explode("\n",$body) as $line){
        if(preg_match('~^\s*\x60{3}~',$line))$fenced=!$fenced;
        if(!$fenced&&preg_match('~^\s*\{\{(list:blog|component:[A-Za-z0-9_-]+|include:partials/[a-z0-9-]+\.md)\}\}\s*$~D',$line,$m)){
            $flush();$out.='<!--tipi:'.$m[1].'-->';
        }else $buf[]=$line;
    }
    $flush();return $out;
}
/** Strict editorial fragment validation. Application code remains in extensions,
 * not in user-supplied HTML. Reject unsafe markup, do not silently strip content. */
function validateEditorialHtml(string $html,bool $allowDynamic=true): string {
    if(strlen($html)>MAX_CONTENT||!preg_match('//u',$html)||!class_exists(DOMDocument::class))fail('HTML trop volumineux, invalide ou extension DOM indisponible',422);
    if(preg_match('~<\s*/?\s*(?:html|head|body|script|style|base|meta|link|svg|math|object|embed|form|input|textarea|template|noscript|plaintext|xmp)\b|<\?|<!DOCTYPE~i',$html))fail('Code applicatif interdit dans le contenu',422);
    $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{
        if(!$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))fail('HTML invalide',422);
        $body=$dom->getElementsByTagName('body')->item(0);
        $allowed=explode(' ','p h1 h2 h3 h4 h5 h6 a img strong b em i u s del small sub sup span div section article figure figcaption ul ol li dl dt dd blockquote pre code hr br table caption thead tbody tfoot tr th td iframe');
        $walk=function(DOMNode $node)use(&$walk,$allowed,$allowDynamic){
            if($node->nodeType===XML_COMMENT_NODE){
                if(!$allowDynamic||!preg_match('~^tipi:(?:list:blog|component:[A-Za-z0-9_-]+|include:partials/[a-z0-9-]+\.md)$~D',$node->nodeValue))fail('Commentaire ou composant HTML non autorisé',422);
                return;
            }
            if($node->nodeType===XML_TEXT_NODE)return;
            if(!$node instanceof DOMElement||!in_array($tag=strtolower($node->tagName),$allowed,true))fail('Balise HTML non autorisée',422);
            foreach($node->attributes as $attr){
                $key=strtolower($attr->name);$value=$attr->value;
                if(str_contains($value,'<!--tipi:')||str_contains($value,'{{'))fail('Composant interdit dans un attribut',422);
                if(in_array($key,['href','src'],true)){
                    if(($key==='href'&&$tag!=='a')||($key==='src'&&!in_array($tag,['img','iframe'],true)))fail('Attribut URL interdit',422);
                    if($value===''||preg_match('~[\x00-\x20\x7f\\\\]~',$value)||str_starts_with($value,'//')||
                        (!preg_match('~^https?://~i',$value)&&!str_starts_with($value,'/')&&!str_starts_with($value,'#')))fail('URL éditoriale non autorisée',422);
                }elseif($key==='style'){
                    if(!preg_match('~^\s*text-align\s*:\s*(?:left|right|center|justify)\s*;?\s*$~iD',$value))fail('Style éditorial non autorisé',422);
                }elseif(!in_array($key,['class','id','title','alt','width','height','loading','decoding','rel','target','colspan','rowspan','scope','start','data-lang','frameborder','allow','allowfullscreen'],true)){
                    fail('Attribut HTML non autorisé : '.$key,422);
                }
                if($key==='target'&&$value!=='_blank'&&$value!=='_self')fail('Cible HTML non autorisée',422);
            }
            if($tag==='iframe'&&!preg_match('~^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{6,}$~D',$node->getAttribute('src')))fail('Iframe non autorisée',422);
            if($tag==='a'&&$node->getAttribute('target')==='_blank')$node->setAttribute('rel','noopener noreferrer');
            foreach($node->childNodes as $child)$walk($child);
        };
        $out='';foreach($body->childNodes as $child){$walk($child);$out.=$dom->saveHTML($child);}
        return $out;
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
}
function canonicalDoc(array $meta,string $body): array {
    $format=$meta['format']??'markdown';
    if(!in_array($format,['markdown','html'],true))fail('Format éditorial inconnu',422);
    $html=validateEditorialHtml($format==='html'?$body:markdownHtml($body));
    $meta['format']='html';
    if(strlen(encodeDoc($meta,$html))>MAX_CONTENT)fail('Document HTML trop volumineux',422);
    return [$meta,$html];
}
function writeDoc(string $d,string $slug,string $state,string $raw): void {
    [$meta,$body]=front($raw);[$meta,$html]=canonicalDoc($meta,$body);
    jwrite(fileDoc($d,$slug,$state),['format'=>'html','meta'=>$meta,'html'=>$html]);
    $legacy=legacyDoc($d,$slug,$state);if(is_file($legacy)&&!unlink($legacy))fail('Ancien document non retiré',500);
}
function removeDoc(string $d,string $slug,string $state): void {
    // Remove legacy first: a crash cannot resurrect a stale Markdown file.
    foreach([legacyDoc($d,$slug,$state),fileDoc($d,$slug,$state)] as $f)if(is_file($f)&&!unlink($f))fail('Suppression impossible',500);
}
function migrateEditorialHtml(string $d,bool $apply): array {
    return locked($d,function()use($d,$apply){
        $pending=[];
        foreach(['published','drafts'] as $state)foreach(documentSlugs($d,$state) as $slug){
            $legacy=legacyDoc($d,$slug,$state);if(!is_file($legacy))continue;
            $old=(string)file_get_contents($legacy);
            if(is_file(fileDoc($d,$slug,$state)))fail('Deux versions de stockage existent : '.$slug,409);
            [$meta,$body]=front($old);[$meta,$html]=canonicalDoc($meta,$body);
            $pending[]=[$state,$slug,$old,encodeDoc($meta,$html)];
        }
        if(!$apply||$pending===[])return ['mode'=>$apply?'applied':'dry_run','count'=>count($pending)];
        $backup=$d.'/html-migration/'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6));
        foreach($pending as [$state,$slug,$old,$new])atomic($backup.'/'.$state.'/'.$slug.'.md',$old);
        jwrite($backup.'/manifest.json',['count'=>count($pending),'created_at'=>gmdate(DATE_ATOM)]);
        foreach($pending as [$state,$slug,$old,$new])writeDoc($d,$slug,$state,$new);
        return ['mode'=>'applied','count'=>count($pending),'backup'=>$backup];
    });
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
    return locked($d,fn()=>saveDocLocked($d,$slug,$meta,$body,$expected,$publish));
}
// Call only while holding the CMS write lock.
function saveDocLocked(string $d,string $slug,array $meta,string $body,string $expected,bool $publish): array {
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
        [$meta,$body]=canonicalDoc($meta,$body);
        $raw=encodeDoc($meta,$body);
        $rev=snapshot($d,$slug);
        writeDoc($d,$slug,$publish?'published':'drafts',$raw);
        if ($publish) removeDoc($d,$slug,'drafts');
        $raw=loadDoc($d,$slug,$publish?'published':'drafts');
        return ['slug'=>$slug,'sha256'=>sha($raw),'revision'=>$rev,'status'=>$publish?'published':'draft','url'=>pageurl($slug)];
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
            foreach($selected as $slug=>$states)foreach($states as $state=>$raw)if($raw!==null)removeDoc($d,$slug,$state);
        }catch(Throwable $ex){
            foreach($selected as $slug=>$states)foreach($states as $state=>$raw)if($raw!==null)writeDoc($d,$slug,$state,$raw);
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
            if (is_string($s[$key])) writeDoc($d,$slug,$state,$s[$key]); else removeDoc($d,$slug,$state);
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
const TIPI_HUB_ORIGIN='https://le-tipi.club';
const TIPI_ANALYTICS_ORIGIN='https://analitycs.le-tipi.club';
const TIPI_OPTIMIZE_ORIGIN='https://optimize.le-tipi.club';
function tipiHubConfig(string $d): array {return jread($d.'/hub.json');}
// Private, server-to-server transport. Never forward Hub credentials to browser JS.
function tipiHubRequest(string $method,string $path,array $payload=[],array $headers=[]): array {
    if(!function_exists('curl_init'))fail('Extension PHP cURL requise pour la connexion au Tipi',503);
    $ch=curl_init(TIPI_HUB_ORIGIN.'/api/plugin/v1/'.$path);
    $headers[]='Accept: application/json';
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false];
    if($method==='POST'){$headers[]='Content-Type: application/json';$options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_THROW_ON_ERROR);}
    $options[CURLOPT_HTTPHEADER]=$headers;
    curl_setopt_array($ch,$options);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $result=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($result)||$status<200||$status>=300||($result['status']??'')!=='ok'){
        if(in_array($status,[401,403],true))fail('Connexion au Tipi refusée ou révoquée',$status);
        fail('Le Tipi est momentanément indisponible ou la demande a expiré. Réessayez.',503);
    }
    return $result;
}
function tipiHubPairing(string $d,array $cfg,string $action,?callable $request=null): void {
    $request=$request??'tipiHubRequest';
    locked($d,function()use($d,$cfg,$action,$request){
        $h=tipiHubConfig($d);$h['installation_uid']=$h['installation_uid']??bin2hex(random_bytes(16));
        if($action==='hub_pair_start'){
            if(!empty($h['access_token']))fail('Déconnectez le site avant de le réassocier',409);
            $p=$request('POST','pairings/init',['platform'=>'tipi_cms','site_url'=>$cfg['url'],'installation_uid'=>$h['installation_uid'],'plugin_version'=>TIPI_VERSION])['pairing']??[];
            if(!preg_match('~^[a-f0-9]{24}$~D',(string)($p['public_id']??''))||!preg_match('~^[a-f0-9]{48}$~D',(string)($p['poll_token']??''))||!preg_match('~^[A-Z2-9]{6}$~D',(string)($p['pairing_code']??'')))fail('Réponse de jumelage invalide',502);
            $h['pairing']=['public_id'=>$p['public_id'],'poll_token'=>$p['poll_token'],'pairing_code'=>$p['pairing_code']];
        }elseif($action==='hub_pair_finish'){
            $p=$h['pairing']??[];
            if(empty($p['public_id'])||empty($p['poll_token']))fail('Lancez d’abord une connexion',409);
            $result=$request('GET','pairings/'.rawurlencode($p['public_id']),[],['X-Tipi-Poll-Token: '.$p['poll_token']])['pairing']??[];
            if(($result['status']??'')==='pending')fail('Autorisez d’abord le site dans le Hub',409);
            if(($result['status']??'')!=='approved'){
                unset($h['pairing']);jwrite($d.'/hub.json',$h);fail('Demande expirée ou déjà utilisée. Relancez la connexion.',409);
            }
            if(($result['platform']??'')!=='tipi_cms'||!preg_match('~^ltcms_[a-f0-9]{48}$~D',(string)($result['access_token']??''))||!preg_match('~^[a-zA-Z0-9_-]{16,128}$~D',(string)($result['tracker_key']??''))||(int)($result['site_id']??0)<=0)fail('Réponse de connexion invalide',502);
            $h['access_token']=$result['access_token'];$h['site_id']=(int)$result['site_id'];$h['tracker_key']=$result['tracker_key'];
            $h['analytics']=true;$h['optimize']=false;$h['hector_write']=false;$h['consent_epoch']=bin2hex(random_bytes(12));unset($h['pairing']);
        }elseif($action==='hub_refresh'||$action==='hub_disconnect'){
            if(!empty($h['access_token'])){
                try{
                    $headers=['Authorization: Bearer '.$h['access_token']];
                    if($action==='hub_disconnect'){$request('POST','revoke',[],$headers);}
                    else{
                        $site=$request('GET','site-config',[],$headers)['site']??[];
                        if(($site['platform']??'')!=='tipi_cms'||(int)($site['site_id']??0)!==(int)$h['site_id']||!preg_match('~^[a-zA-Z0-9_-]{16,128}$~D',(string)($site['tracker_key']??'')))fail('Configuration Hub incohérente',502);
                        if($site['tracker_key']!==$h['tracker_key']){$h['tracker_key']=$site['tracker_key'];$h['consent_epoch']=bin2hex(random_bytes(12));}
                    }
                }catch(RuntimeException $ex){
                    if(!in_array($ex->getCode(),[401,403],true))throw $ex;
                    $action='hub_disconnect';
                }
            }
            if($action==='hub_disconnect'){
                unset($h['access_token'],$h['site_id'],$h['pairing'],$h['hector_sync'],$h['hector_write']);$h['tracker_key']='';$h['analytics']=false;$h['optimize']=false;$h['consent_epoch']=bin2hex(random_bytes(12));
            }
        }
        jwrite($d.'/hub.json',$h);
    });
}
// Complete, bounded inventory of published source documents; drafts are never transmitted.
function tipiHectorDocuments(string $d): array {
    $docs=[];$ids=[];
    if(!is_dir($d.'/published')||!is_readable($d.'/published'))fail('Stockage éditorial indisponible',503);
    foreach(documentSlugs($d,'published') as $slug){
        $raw=loadDoc($d,$slug,'published');
        if($raw===null||strlen($raw)>MAX_CONTENT)fail('Inventaire éditorial incomplet',409);
        [$meta,$body]=front($raw);$uid=$meta['id']??'';
        if(!preg_match('/^[a-f0-9]{24}$/D',$uid)||isset($ids[$uid]))fail('Identité de document manquante ou dupliquée : '.$slug,409);
        $ids[$uid]=true;
        $docs[]=['source_uid'=>$uid,'slug'=>$slug,'title'=>$meta['title'],'seo_title'=>$meta['seo_title']??'',
            'meta_description'=>$meta['description']??'','modified'=>$meta['modified']??$meta['published_at']??'',
            'author_name'=>$meta['author']??'','category_name'=>$meta['category']??'','revision'=>sha($raw),
            'content_markdown'=>($meta['format']??'markdown')==='html'?'':$body,'content_html'=>($meta['format']??'markdown')==='html'?$body:markdownHtml($body),'content_format'=>($meta['format']??'markdown'),
            'has_draft'=>loadDoc($d,$slug,'drafts')!==null,'dynamic'=>dynamicHtml($body)];
        if(count($docs)>200)fail('Inventaire supérieur à 200 documents : synchronisation par lots nécessaire',413);
    }
    usort($docs,static fn($a,$b)=>strcmp($a['slug'],$b['slug']));
    return $docs;
}
function tipiHectorSync(string $d,array $cfg,?callable $request=null): array {
    $request=$request??'tipiHubRequest';
    return locked($d,function()use($d,$cfg,$request){
        $h=tipiHubConfig($d);
        if(empty($h['access_token'])||empty($h['installation_uid']))fail('Connectez d’abord votre site au Tipi',409);
        $sequence=(int)($h['hector_sequence']??0)+1;
        $payload=['site_url'=>$cfg['url'],'installation_uid'=>$h['installation_uid'],'sequence'=>$sequence,'direct_api'=>1,'documents'=>tipiHectorDocuments($d)];
        if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>750000)fail('Inventaire trop volumineux : synchronisation par lots nécessaire',413);
        // Reserve the sequence before sending. A lost response cannot replay an older snapshot.
        $h['hector_sequence']=$sequence;jwrite($d.'/hub.json',$h);
        $result=$request('POST','hector/cms/inventory',$payload,['Authorization: Bearer '.$h['access_token']])['inventory']??[];
        if(($result['platform']??'')!=='tipi_cms'||($result['mode']??'')!=='read_only'||(int)($result['sequence']??0)!==$sequence||(int)($result['count']??-1)!==count($payload['documents']))fail('Hector n’a pas confirmé l’inventaire complet',502);
        $h['hector_sync']=['count'=>(int)$result['count'],'articles'=>(int)($result['articles']??0),'synced_at'=>(string)($result['synced_at']??gmdate(DATE_ATOM))];
        jwrite($d.'/hub.json',$h);
        return $h['hector_sync'];
    });
}
// Explicit local opt-in. Commands are pulled from the fixed Hub origin, never
// from a member-controlled URL. The only writable surface is editorial Markdown.
function tipiHectorWriteSettings(string $d,bool $enabled): void {
    locked($d,function()use($d,$enabled){
        $h=tipiHubConfig($d);if(empty($h['access_token']))fail('Connectez d’abord le site',409);
        $h['hector_write']=$enabled;jwrite($d.'/hub.json',$h);
    });
}
function tipiHectorApply(string $d,array $command,string $token,bool $direct=false): array {
    return locked($d,function()use($d,$command,$token,$direct){
        $h=tipiHubConfig($d);
        if(empty($h['hector_write'])||empty($h['access_token'])||!hash_equals($h['access_token'],$token))fail('Écritures Hector désactivées',403);
        $id=$command['id']??'';$slug=$command['slug']??'';$expected=$command['expected_revision']??null;
        $mode=$command['mode']??'';$fields=$command['fields']??null;
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_string($slug)||!is_string($expected)
            ||($expected!==''&&!preg_match('/^[a-f0-9]{64}$/D',$expected))||!in_array($mode,['draft','publish'],true)
            ||!is_array($fields)||$fields===[])fail('Commande éditoriale invalide',422);
        slugok($slug);
        if(!$direct&&$expected===''&&!str_starts_with($slug,'blog/'))fail('Seuls les nouveaux articles sont autorisés',422);
        $receiptFile=$d.'/hector-receipts/'.$id.'.json';
        $receipt=jread($receiptFile);
        if($receipt!==[])return $receipt;
        $live=loadDoc($d,$slug,'published');$draft=loadDoc($d,$slug,'drafts');
        // A crash between the atomic document write and receipt persistence is safe.
        $written=$mode==='publish'?$live:$draft;
        if($written!==null && (front($written)[0]['hector_operation']??'')===$id){
            $receipt=['id'=>$id,'status'=>'applied','revision'=>sha($written),'mode'=>$mode];
            jwrite($receiptFile,$receipt);return $receipt;
        }
        if($draft!==null||!hash_equals(sha($live),$expected))fail('Un brouillon ou une version plus récente existe',409);
        [$meta,$body]=$live!==null?front($live):[['title'=>'','description'=>'','date'=>gmdate('Y-m-d')],''];
        if(dynamicHtml($body))fail('Document dynamique protégé',409);
        if(isset($fields['content_html'],$fields['content_markdown']))fail('Un seul format de contenu accepté',422);
        $map=['title'=>'title','seo_title'=>'seo_title','meta_description'=>'description','author_name'=>'author','category_name'=>'category'];
        foreach($fields as $key=>$value){
            if(!is_string($value)||!preg_match('//u',$value))fail('Champ éditorial invalide',422);
            if($key==='content_html'){
                if(dynamicHtml($value))fail('Composant dynamique interdit',422);
                $body=validateEditorialHtml($value,false);$meta['format']='html';
            }elseif($key==='content_markdown'){
                if(strlen($value)>MAX_CONTENT||str_contains($value,'{{'))fail('Markdown dynamique ou trop volumineux',422);
                $body=$value;$meta['format']='markdown';
            }elseif(isset($map[$key])){
                if(strlen($value)>2000||preg_match('/[\r\n\x00]/',$value))fail('Métadonnée invalide',422);
                $meta[$map[$key]]=$value;
            }else fail('Champ interdit',422);
        }
        if(trim($meta['title'])===''||($live===null&&trim($body)===''))fail('Titre et contenu requis',422);
        $meta['hector_operation']=$id;
        $raw=encodeDoc($meta,$body);
        if(strlen($raw)>MAX_CONTENT)fail('Contenu trop volumineux',422);
        $result=saveDocLocked($d,$slug,$meta,$body,$expected,$mode==='publish');
        $receipt=['id'=>$id,'status'=>'applied','revision'=>$result['sha256'],'mode'=>$mode];
        jwrite($receiptFile,$receipt);return $receipt;
    });
}
/** The pairing bearer never leaves its original direction. Derive a separate
 * HMAC key for authenticated inbound requests; no general API/MCP privilege. */
function tipiEditorialKey(string $bearer): string {
    return hash_hmac('sha256','tipi-editorial-api-v1',$bearer);
}
function tipiEditorialSignature(string $key,string $method,string $path,string $timestamp,string $body): string {
    return hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$timestamp."\n".hash('sha256',$body),$key);
}
function tipiEditorialAuthenticate(array $hub,string $method,string $path,string $timestamp,string $signature,string $body,?int $now=null): string {
    $token=$hub['access_token']??'';
    if(!is_string($token)||$token===''||!preg_match('/^\d{10}$/D',$timestamp)||abs(($now??time())-(int)$timestamp)>120||
       !preg_match('/^[a-f0-9]{64}$/D',$signature)||!hash_equals(tipiEditorialSignature(tipiEditorialKey($token),$method,$path,$timestamp,$body),$signature))
        fail('Authentification éditoriale refusée',401);
    return $token;
}
function tipiCategorySlug(string $name): string {
    $ascii=function_exists('iconv')?iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name):$name;
    $slug=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($ascii?:$name))??'','-');
    return substr($slug?:'categorie-'.substr(hash('sha256',$name),0,12),0,90);
}
function tipiEditorialCategories(string $d): array {
    $catalog=jread($d.'/categories.json');
    $known=[];
    foreach($catalog as $slug=>$row)$known[mb_strtolower($row['name'])]=$slug;
    // Legacy categories are stored as article metadata; include unpublished
    // assignments without exposing draft text or titles.
    $counts=[];
    foreach(['published','drafts'] as $state)foreach(inventory($d,$state) as $slug=>$meta){
        $name=trim((string)($meta['category']??''));if($name==='')continue;
        $key=mb_strtolower($name);$id=$known[$key]??null;
        if($id===null){
            $id=tipiCategorySlug($name);if(isset($catalog[$id]))$id=substr($id,0,76).'-'.substr(hash('sha256',$name),0,10);
            $catalog[$id]=['name'=>$name,'description'=>''];$known[$key]=$id;
        }
        $counts[$id][$slug]=true;
    }
    ksort($catalog);$out=[];
    foreach($catalog as $slug=>$row)$out[]=['slug'=>$slug,'name'=>$row['name'],'description'=>$row['description']??'',
        'revision'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),'assigned_count'=>count($counts[$slug]??[])];
    return $out;
}
function tipiEditorialCategorySave(string $d,string $slug,array $input,string $token): array {
    return locked($d,function()use($d,$slug,$input,$token){
        $h=tipiHubConfig($d);
        if(empty($h['hector_write'])||!hash_equals((string)($h['access_token']??''),$token))fail('Écritures Hector désactivées',403);
        if(!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D',$slug))fail('Slug de catégorie invalide',422);
        $name=$input['name']??null;$description=$input['description']??'';$expected=$input['expected_revision']??null;
        if(!is_string($name)||trim($name)===''||strlen($name)>500||!preg_match('//u',$name)||preg_match('/[\x00-\x1f<>]/',$name)||
            !is_string($description)||strlen($description)>2000||!preg_match('//u',$description)||!is_string($expected))fail('Catégorie invalide',422);
        $name=trim($name);$current=null;
        foreach(tipiEditorialCategories($d) as $row){
            if($row['slug']===$slug)$current=$row;
            elseif(mb_strtolower($row['name'])===mb_strtolower($name))fail('Nom de catégorie déjà utilisé',409);
        }
        $catalog=jread($d.'/categories.json');
        $record=['name'=>$name,'description'=>$description];
        if(isset($catalog[$slug])&&$catalog[$slug]===$record)return $current;
        if(!hash_equals((string)($current['revision']??''),$expected))fail('Catégorie modifiée',409);
        if($current!==null&&$current['assigned_count']>0&&$current['name']!==$name)fail('Renommage d’une catégorie affectée : modifier les affectations éditoriales explicitement',409);
        $catalog[$slug]=$record;jwrite($d.'/categories.json',$catalog);
        return ['slug'=>$slug,'name'=>$name,'description'=>$description,'revision'=>hash('sha256',json_encode($record,JSON_THROW_ON_ERROR)),
            'assigned_count'=>$current['assigned_count']??0];
    });
}
function tipiEditorialApi(string $d,array $cfg,string $path): never {
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    $raw=(string)file_get_contents('php://input',false,null,0,MAX_CONTENT+65537);
    if(strlen($raw)>MAX_CONTENT+65536)fail('Requête trop volumineuse',413);
    $h=tipiHubConfig($d);
    $token=tipiEditorialAuthenticate($h,$method,$path,(string)($_SERVER['HTTP_X_TIPI_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_TIPI_SIGNATURE']??''),$raw);
    $base='/api/v1/hector/';
    if($method==='GET'&&$path===$base.'capabilities')jsonout(['version'=>1,'storage'=>'html','installation_uid'=>$h['installation_uid'],
        'write'=>!empty($h['hector_write']),'documents'=>true,'categories'=>true,'authors'=>true,'max_snapshot_documents'=>200]);
    if($method==='POST'&&$path===$base.'inventory'){
        $result=locked($d,function()use($d,$cfg,$token){
            $h=tipiHubConfig($d);if(!hash_equals((string)($h['access_token']??''),$token))fail('Connexion remplacée',401);
            $sequence=(int)($h['hector_sequence']??0)+1;
            $payload=['site_url'=>$cfg['url'],'installation_uid'=>$h['installation_uid'],'sequence'=>$sequence,'documents'=>tipiHectorDocuments($d),
                'write'=>!empty($h['hector_write'])];
            if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>750000)fail('Inventaire trop volumineux',413);
            $h['hector_sequence']=$sequence;jwrite($d.'/hub.json',$h);return $payload;
        });jsonout($result);
    }
    if($method==='GET'&&$path===$base.'documents'){
        $items=locked($d,function()use($d){
            $out=[];foreach(inventory($d) as $slug=>$meta)$out[]=['slug'=>$slug,'title'=>$meta['title'],'revision'=>sha(loadDoc($d,$slug,'published')),
                'type'=>str_starts_with($slug,'blog/')?'post':'page','has_draft'=>loadDoc($d,$slug,'drafts')!==null];
            return $out;
        });jsonout(['documents'=>$items]);
    }
    if($method==='GET'&&str_starts_with($path,$base.'documents/')){
        $slug=slugok(substr($path,strlen($base.'documents/')));
        $doc=locked($d,function()use($d,$slug){
            foreach(tipiHectorDocuments($d) as $doc)if($doc['slug']===$slug)return $doc;
            fail('Document introuvable',404);
        });jsonout(['document'=>$doc]);
    }
    if($method==='GET'&&$path===$base.'categories')jsonout(['categories'=>locked($d,fn()=>tipiEditorialCategories($d))]);
    if($method==='GET'&&$path===$base.'authors'){
        $authors=locked($d,function()use($d){$out=[];foreach(inventory($d) as $meta){$name=trim((string)($meta['author']??''));if($name!=='')$out[$name]=true;}return array_keys($out);});
        jsonout(['authors'=>$authors]);
    }
    if($method==='PUT'&&str_starts_with($path,$base.'categories/')){
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($data))fail('Catégorie invalide',422);
        jsonout(['category'=>tipiEditorialCategorySave($d,substr($path,strlen($base.'categories/')),$data,$token)]);
    }
    if($method==='POST'&&$path===$base.'commands'){
        $command=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($command))fail('Commande invalide',422);
        jsonout(['receipt'=>tipiHectorApply($d,$command,$token,true)]);
    }
    fail('Route éditoriale inconnue',404);
}

function tipiHectorRun(string $d,array $cfg,?callable $request=null): array {
    $lock=fopen($d.'/hector-run.lock','c');
    if(!$lock)fail('Verrou Hector indisponible',500);
    try{
        if(!flock($lock,LOCK_EX|LOCK_NB))fail('Un traitement Hector est déjà en cours',409);
        return tipiHectorRunLocked($d,$cfg,$request);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function tipiHectorRunLocked(string $d,array $cfg,?callable $request=null): array {
    $request=$request??'tipiHubRequest';$h=tipiHubConfig($d);
    if(empty($h['access_token']))fail('Connectez d’abord le site',409);
    // Sync every run, even if editorial writes are disabled.
    $sync=tipiHectorSync($d,$cfg,$request);
    if(empty($h['hector_write']))return ['inventory'=>$sync,'command'=>null];
    $token=$h['access_token'];$headers=['Authorization: Bearer '.$token];
    $context=['site_url'=>$cfg['url'],'installation_uid'=>$h['installation_uid']];
    // An acknowledgement lost in transit must survive even after the command expires.
    $pendingFile=$d.'/hector-pending-ack.json';
    $pending=jread($pendingFile);
    if(($pending['binding']??'')===hash('sha256',$token)&&is_array($pending['receipt']??null)){
        $receipt=$pending['receipt'];
        $ack=$request('POST','hector/cms/commands',$context+['receipt'=>$receipt],$headers)['commands']??[];
        if(($ack['acknowledged']??null)!==$receipt['id'])fail('Accusé de réception Hector manquant',502);
        // Retain the file: its exact receipt can safely be retried. Replace it with
        // an empty record atomically rather than unlinking across concurrent runs.
        locked($d,function()use($pendingFile,$pending){if(jread($pendingFile)===$pending)jwrite($pendingFile,[]);});
        return ['inventory'=>$sync,'command'=>$receipt];
    }
    $command=$request('POST','hector/cms/commands',$context,$headers)['commands']['command']??null;
    if($command===null)return ['inventory'=>$sync,'command'=>null];
    if(!is_array($command))fail('Commande Hector invalide',502);
    try{$receipt=tipiHectorApply($d,$command,$token);}
    catch(RuntimeException $ex){
        if(!in_array($ex->getCode(),[400,409,422],true))throw $ex;
        $receipt=['id'=>$command['id']??'','status'=>'rejected','reason'=>$ex->getCode()===409?'conflict':'invalid'];
    }
    // Persist acknowledgement before the next network call.
    $pending=['binding'=>hash('sha256',$token),'receipt'=>$receipt];
    locked($d,fn()=>jwrite($pendingFile,$pending));
    // Confirm the new published snapshot before acknowledging success.
    if($receipt['status']==='applied')$sync=tipiHectorSync($d,$cfg,$request);
    $ack=$request('POST','hector/cms/commands',$context+['receipt'=>$receipt],$headers)['commands']??[];
    if(($ack['acknowledged']??null)!==$receipt['id'])fail('Accusé de réception Hector manquant',502);
    locked($d,function()use($pendingFile,$pending){if(jread($pendingFile)===$pending)jwrite($pendingFile,[]);});
    return ['inventory'=>$sync,'command'=>$receipt];
}
function tipiHubEnabled(array $h): bool {return ($h['analytics']??false)===true&&is_string($h['tracker_key']??null)&&preg_match('~^[a-zA-Z0-9_-]{16,128}$~D',$h['tracker_key'])===1;}
function tipiHubBinding(array $h): string {return substr(hash('sha256',($h['installation_uid']??'').'|'.($h['consent_epoch']??'').'|'.($h['tracker_key']??'').'|'.(!empty($h['optimize'])?'1':'0')),0,32);}
function tipiHubConsent(array $h): bool {return tipiHubEnabled($h)&&hash_equals(tipiHubBinding($h).'.yes',(string)($_COOKIE['tipi_metrics_consent']??''));}
function tipiHubStatus(string $d): array {
    $h=tipiHubConfig($d);
    return ['mode'=>!empty($h['access_token'])?'paired':(tipiHubEnabled($h)?'manual':'disconnected'),'analytics'=>tipiHubEnabled($h),'optimize'=>tipiHubEnabled($h)&&!empty($h['optimize']),'consent'=>'explicit_opt_in','html_contract'=>'data-ta-event / data-ta-revenue / data-ta-meta-*','javascript'=>'Tipi.track(name, meta) delegates to TipiAnalitycs.event','php'=>'Tipi::track(name, meta, stable_reference) queues confirmed public POST events after consent','server_queue_pending'=>count(glob($d.'/hub-outbox/*.json')?:[]),'pairing_ready'=>true,'hector_sync'=>$h['hector_sync']??null,'revenue_provider_ready'=>false];
}
function tipiHubSettings(string $d,array $post): void {
    $key=trim((string)($post['tracker_key']??''));
    if($key!==''&&!preg_match('~^[a-zA-Z0-9_-]{16,128}$~D',$key))fail('Clé de suivi invalide');
    locked($d,function()use($d,$post,$key){
        $h=tipiHubConfig($d);$old=$h;
        if(($post['action']??'')==='hub_disconnect'){
            if(!empty($h['access_token']))fail('Utilisez la déconnexion native pour révoquer le jeton Hub',409);
            $h['analytics']=false;$h['optimize']=false;$h['tracker_key']='';
        }else{
            if(!empty($h['access_token'])&&$key!==''&&$key!==($h['tracker_key']??''))fail('La clé est gérée par le Hub',409);
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
    $pairing=$h['pairing']??[];
    $connect='<div class="panel"><h2>Connexion native au Tipi</h2>';
    if(!empty($h['access_token'])){
        $sync=$h['hector_sync']??[];
        $connect.='<p>Site associé au Hub #'.(int)$h['site_id'].'.</p><form method="post">'.csrf().'<button name="action" value="hub_refresh">Vérifier la connexion</button></form>';
        $connect.='<h3>Hector : synchronisation éditoriale</h3><p>'.(!empty($sync['synced_at'])?(int)$sync['count'].' document(s), dont '.(int)$sync['articles'].' article(s), synchronisés le '.e($sync['synced_at']):'Aucun inventaire transmis à Hector.').'</p><form method="post">'.csrf().'<button name="action" value="hector_sync">Activer / actualiser la connexion éditoriale Hector</button></form>';
        $connect.='<form method="post">'.csrf().'<label><input type="checkbox" name="hector_write" '.(!empty($h['hector_write'])?'checked':'').'> Autoriser Hector à créer des articles, pages, catégories et modifier les contenus éditoriaux (brouillons ou publications)</label><button name="action" value="hector_write_save">Enregistrer les droits Hector</button></form><form method="post">'.csrf().'<button name="action" value="hector_run">Traitement manuel de secours (ancienne connexion)</button></form>';
    }elseif(!empty($pairing['public_id'])){
        $connect.='<p>Code à saisir sur le Hub : <strong>'.e($pairing['pairing_code']).'</strong></p><p><a target="_blank" rel="noopener noreferrer" href="'.TIPI_HUB_ORIGIN.'/plugin/autoriser?pairing='.rawurlencode($pairing['public_id']).'">Autoriser ce site dans le Hub</a></p><form method="post">'.csrf().'<button name="action" value="hub_pair_finish">Terminer la connexion</button><button class="secondary" name="action" value="hub_pair_start">Nouvelle demande</button></form>';
    }else{
        $connect.='<p>Associez ce domaine à votre compte Tipi.</p><form method="post">'.csrf().'<button name="action" value="hub_pair_start">Connecter mon site au Tipi</button></form>';
    }
    $connect.='</div>';
    return '<h1>Le Tipi</h1>'.$connect.'<div class="panel"><h2>'.(tipiHubEnabled($h)?'Suivi configuré':'Connecter votre site').'</h2><p>Réglages du suivi. La clé publique est fournie par le Hub lors de l’association ; la configuration manuelle reste possible hors association.</p><form method="post">'.csrf().'<label>Clé publique Analytics (laisser vide pour conserver)<input name="tracker_key" autocomplete="off" placeholder="'.(!empty($h['tracker_key'])?'Clé enregistrée':'Clé du site').'"></label><label><input style="display:inline;width:auto" type="checkbox" name="analytics" '.(!empty($h['analytics'])?'checked':'').'> Activer Analytics sur le site public</label><label><input style="display:inline;width:auto" type="checkbox" name="optimize" '.(!empty($h['optimize'])?'checked':'').'> Activer Optimize après consentement</label><p>Le suivi reste désactivé tant que le visiteur n’a pas accepté. L’administration et les prévisualisations sont exclues.</p><button name="action" value="hub_save">Enregistrer</button><button class="secondary" name="action" value="hub_disconnect">Déconnecter</button></form></div><h2>Événements métier</h2><p>'.(int)$status['server_queue_pending'].' événement(s) PHP en attente. Les clics HTML et événements JS utilisent directement le tracker existant.</p><form method="post">'.csrf().'<button name="action" value="hub_flush" class="secondary">Réessayer les envois PHP</button></form><p><a href="https://le-tipi.club/dashboard">Ouvrir le Tipi Hub</a></p><p>La réception des événements n’est pas confirmée par la simple présence de la clé. Vérifiez le flux temps réel du Hub.</p>';
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
    if($consented)$panel.='<button type="button" data-tipi-consent-choice="no">Retirer mon accord de mesure</button>';
    elseif(hash_equals($binding.'.no',$decision))$panel.='<button type="button" data-tipi-consent-choice="reset">Préférences de mesure d’audience</button>';
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
    $html=($m['format']??'markdown')==='html'
        ?preg_replace_callback('~<!--tipi:(list:blog|component:[A-Za-z0-9_-]+|include:partials/[a-z0-9-]+\.md)-->~',fn($match)=>renderBody($d,'{{'.$match[1].'}}',$preview?[]:$cfg),$body)
        :renderBody($d,$body,$preview?[]:$cfg);
    if (!preg_match('~<h1(?:\s|>)~i',$html)) $html='<h1>'.e($m['title']).'</h1>'.$html;
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
            foreach($seed as $slug=>$raw){[$m,$b]=front($raw);$m['id']=bin2hex(random_bytes(12));$m['published_at']=gmdate(DATE_ATOM);writeDoc($d,$slug,'published',encodeDoc($m,$b));}
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
    shell('Installer Tipi CMS','<div class="panel"><h1>Installer Tipi CMS</h1><p>HTML natif, PHP, sans base de données.</p><form method="post">'.csrf().field('key','Clé d’installation','','password').field('name','Nom du site').field('url','URL du site','https://').field('password','Mot de passe administrateur (12 caractères minimum)','','password').'<button>Installer</button></form></div>');
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
        if($act==='hector_write_save'){tipiHectorWriteSettings($d,isset($_POST['hector_write']));redirect('/admin?hub=1');}
        if($act==='hector_run'){tipiHectorRun($d,$cfg);redirect('/admin?hub=1');}
        if($act==='hector_sync'){tipiHectorSync($d,$cfg);redirect('/admin?hub=1');}
        if(in_array($act,['hub_save','hub_disconnect','hub_flush','hub_pair_start','hub_pair_finish','hub_refresh'],true)){
            if($act==='hub_flush')tipiHubFlush($d);elseif($act==='hub_save')tipiHubSettings($d,$_POST);else tipiHubPairing($d,$cfg,$act);
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
        $body.='<details><summary>Connexions techniques avancées</summary><h2>API éditoriale</h2><p>Endpoint : <code>'.e($cfg['url'].'/api/v1').'</code>. Les clés se gèrent dans Contenus.</p><p>Le Tipi : association dans la rubrique Le Tipi.</p></details>';
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
        $body='<h1>'.($slug===''?'Nouvelle page':e($slug)).'</h1><form method="post">'.csrf().'<input type="hidden" name="expected" value="'.sha($raw).'">'.field('slug','Identifiant (ex. services ou blog/article)',$slug).'<label>Contenu (front matter + HTML ou Markdown)<textarea name="raw" required>'.e($rawForm).'</textarea></label><button name="action" value="draft" class="secondary">Enregistrer le brouillon</button><button name="action" value="publish">Publier</button></form>';
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
    if($method==='GET'&&$path==='/api/v1/capabilities')jsonout(['version'=>TIPI_VERSION,'storage'=>'html','accepted_formats'=>['html','markdown'],'publish'=>!empty($cfg['api_publish']),'mcp'=>true,'mcp_auth'=>'bearer','codegraft_bridge'=>is_file(dirname(__DIR__).'/tipi-system/bridge.php'),'hub'=>!empty(tipiHubConfig($d)['access_token']),'hub_tracking'=>tipiHubStatus($d)]);
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
    $raw=['type'=>'string','description'=>'Document complet avec front matter. format: html pour le corps HTML ; sans format, Markdown accepté.'];
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
        $tool('cms_publish','Publie un document HTML ou Markdown complet. Requiert publication.',$input(['slug'=>$slug,'raw'=>$raw,'expected_sha256'=>$hash],['slug','raw','expected_sha256']),false)
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
    if($name==='cms_site_info')return ['name'=>$cfg['name'],'url'=>$cfg['url'],'version'=>TIPI_VERSION,'storage'=>'html','accepted_formats'=>['html','markdown'],'tracking'=>tipiHubStatus($d),'draft_write'=>!empty($cfg['mcp_write']),'publish'=>!empty($cfg['mcp_publish']),'ai_profile'=>$cfg['ai_profile']??'read','design'=>['read'=>true,'write'=>!empty($cfg['mcp_write'])&&!empty($cfg['mcp_publish']),'layouts'=>['classic','wide','centered','editorial'],'custom_css'=>true],'business'=>['read'=>!empty($cfg['business_read']),'write'=>!empty($cfg['business_write']),'development'=>($cfg['ai_profile']??'read')==='build','guide'=>'Utiliser cms_developer_guide : créer des routes, vues, contrôleurs, services, actions MCP, layouts HTML, composants et assets CSS/JS, puis préparer et vérifier un patch avec cg_review_release. Respecter le mode de validation sélectionné.']];
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
    if(str_starts_with($path,'/api/v1/hector/'))tipiEditorialApi($d,$cfg,$path);
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
    if(($argv[1]??'')==='--tipi-migrate-html'){
        try{echo json_encode(migrateEditorialHtml(dataDir(),in_array('--apply',$argv,true)),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;exit;}
        catch(Throwable $ex){fwrite(STDERR,"Migration HTML interrompue : ".$ex->getMessage().PHP_EOL);exit(1);}
    }
    if(($argv[1]??'')==='--tipi-run-hector'){
        try{$d=dataDir();$cfg=jread($d.'/config.json');if(!$cfg)fail('CMS non installé',409);
            echo json_encode(tipiHectorRun($d,$cfg),JSON_THROW_ON_ERROR).PHP_EOL;exit;
        }catch(Throwable $ex){fwrite(STDERR,"Traitement Hector non confirmé : ".$ex->getMessage().PHP_EOL);exit(1);}
    }
    if(($argv[1]??'')==='--tipi-sync-hector'){
        try{
            $d=dataDir();$cfg=jread($d.'/config.json');
            if(!$cfg)fail('CMS non installé',409);
            echo json_encode(tipiHectorSync($d,$cfg),JSON_THROW_ON_ERROR).PHP_EOL;exit;
        }catch(Throwable $ex){fwrite(STDERR,"Synchronisation Hector non confirmée : ".$ex->getMessage().PHP_EOL);exit(1);}
    }
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
