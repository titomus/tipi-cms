# Tipi CMS 0.4 preview

PHP 8.2+, CMS flat-file inspiré du Mini CMS Oncle Web. Sans BDD, sans Composer sur le serveur. Version de test, pas encore destinée à la production.

## Installer

Téléchargez `tipi-install.php` dans un dossier local, puis lancez `php tipi-install.php --prepare`. Le fichier généré `tipi-install-ready.php` contient uniquement l'empreinte d'une clé aléatoire ; conservez la clé affichée. Téléversez ce fichier dans la racine HTTP **vide d'un domaine de test**. Ouvrez /tipi-install-ready.php, entrez la clé, le nom, l'URL et un mot de passe de 12 caractères. L'installateur écrit index.php, .htaccess et le stockage privé, puis se neutralise. HTTPS obligatoire hors localhost.

Alternative : définir TIPI_SETUP_KEY côté serveur avant d'ouvrir tipi-install.php ; choisir une clé aléatoire longue, puis supprimer la variable une fois installé.

Le stockage est par défaut HOME/.tipi-cms/<empreinte>. Vérifiez qu'il est hors de **toutes** les racines web du compte. Sinon définissez TIPI_DATA_DIR (chemin absolu privé). Apache avec mod_rewrite et AllowOverride ; pour Nginx configurer un front controller équivalent. Ne jamais installer par-dessus un site existant. Les sous-dossiers URL ne sont pas pris en charge.

## Test local
```sh
mkdir -p /tmp/tipi-preview/public
cp tipi-install.php /tmp/tipi-preview/public/
cd /tmp/tipi-preview/public
TIPI_SETUP_KEY='une-cle-de-test-longue' TIPI_DATA_DIR=/tmp/tipi-preview/private php -S 127.0.0.1:8080 tipi-install.php
```
Ouvrez http://127.0.0.1:8080/tipi-install.php. Après installation, arrêtez le serveur et relancez avec index.php comme routeur (l'installateur a été supprimé). URL : http://127.0.0.1:8080. Pour Windows, utilisez des variables d'environnement PowerShell et des dossiers absolus séparés.

## Usage
/admin : création, modification Markdown, brouillon privé, publication, prévisualisation, restauration d'une révision. Les brouillons ne remplacent pas le contenu public. Les clés API sont hachées, révocables, publication désactivée par défaut.

Métadonnées : title, seo_title, description, date, author, noindex (true), nav (ordre), nav_label. Identifiant stable créé à la première sauvegarde. Syntaxe front matter simple clé: valeur sur une ligne. Pages à la racine et articles /blog/slug. Shortcodes sur une ligne : {{list:blog}} et {{include:partials/nom.md}} (fichiers Markdown privés à déposer manuellement). HTML brut et exécution PHP depuis Markdown désactivés. Le parseur hérité n'est pas un parseur CommonMark complet.

API avec Authorization: Bearer <clé> :
- GET /api/v1/capabilities
- GET /api/v1/pages
- GET /api/v1/pages/blog/bienvenue
- PUT /api/v1/pages/<slug> : JSON { "raw": "---\ntitle: Titre\n---\nTexte", "expected_sha256": "", "publish": false }. Utiliser le SHA retourné par GET pour modifier une page existante. Conflit HTTP 409 si modification concurrente.

SEO : HTML serveur, title, description, canonical, robots, sitemap, JSON-LD, Open Graph, 404. Le rendu est dynamique ; aucun benchmark ni cache HTML statique dans cette version.

## Limites
Le Hub et Hector ne sont pas encore connectés ; CodeGraft est disponible en option depuis la V0.3. Le MCP éditorial natif est indépendant de CodeGraft ; il utilise une clé Bearer, sans OAuth ni connexion ChatGPT certifiée. Le noyau reste une preview ; tests de déploiement mutualisé, sécurité avancée, médias, thèmes personnalisables, cache statique, redirections et intégration CodeGraft à réaliser. Ne pas réutiliser l'ancien mcp.php d'Oncle Web. Ne pas copier les données d'Oncle Web. Sauvegarder le dossier privé.

## Mise à jour 0.1 → 0.2
Depuis le clone, lancez les tests puis `bash scripts/update.sh /chemin/absolu/public`. Le script remplace seulement index.php, conserve une copie hors racine publique et ne touche pas au stockage Markdown ni à .htaccess. Ne relancez pas l'installateur. Gardez le même document root et les mêmes variables d'environnement.

## Administration 0.2
Apparence : trois palettes et couleur personnalisée. Connexions : clé MCP indépendante, lecture seule au départ, écriture de brouillons et publication activables séparément, révocation. Aucun accès au système de fichiers ou PHP. Le serveur /mcp implémente une partie de Streamable HTTP JSON, négociation 2025-11-25 et 2025-06-18, sans SSE, sans OAuth. Pour un client acceptant Authorization: Bearer, envoyez initialize puis tools/list et tools/call. Les outils disponibles : cms_site_info, cms_list_pages, cms_read_page, cms_save_draft, cms_publish. L'endpoint Bearer est distinct de la passerelle OAuth CodeGraft optionnelle de la V0.3 ; le Hub reste à intégrer. Ne pas publier une clé dans un chat ou URL.

## V0.3 : CodeGraft et applications métier (optionnel)
Le CMS éditorial reste sans SQL. Le moteur OAuth officiel de CodeGraft Framework utilise une petite base SQLite privée lorsqu'il est activé. Les fonctions métier peuvent ouvrir leur propre SQLite à la demande, séparé des données éditoriales et d'OAuth. L'intégration reprend les classes ActionRegistry, ActionSchema, Database et le moteur CodeGraft du commit Framework e128e4bb045df11111729a4b13724976f40b7cdc, sans installer son MVC complet. Les 17 fichiers PHP nécessaires sont embarqués dans `bundled/`, avec un manifeste d'origine et une vérification d'intégrité. L'installation de CodeGraft ne contacte ni GitHub ni aucun autre serveur. Aucun compte ni jeton GitHub n'est nécessaire.

Après update.sh, lancer `bash scripts/install-codegraft.sh "$HOME/tipi_cms" https://cms.le-tipi.club`. PHP 8.2+, PDO SQLite en CLI et Web, Apache rewrite et HTTPS direct, droits du compte hébergeur et copie complète de la distribution Tipi CMS (incluant `bundled/`). Le moteur est placé hors public dans tipi-system ; le coffre OAuth hors projet. L'installateur ne remplace pas le CMS, .htaccess ni les Markdown. Le connecteur ChatGPT OAuth doit utiliser /codegraft-api/ (avec slash), après connexion à /admin. La page Assistant IA propose trois profils et configure les dossiers et permissions automatiquement. Les commandes techniques sont indisponibles via cette passerelle. Les outils cg_* restent ceux du moteur épinglé.

### Développer une fonctionnalité
Créer un fichier autonome dans app/McpActions, retournant une fonction (CGF\\ActionRegistry, TipiBusinessContext) : void. Déclarer chaque action par register(nom, description, mode read/write, schéma, handler, autorisation optionnelle). Le propriétaire inspecte puis installe et active le patch depuis /admin?connections=1 ; seules les versions exactes approuvées sont activées. Les versions modifiées ou nouvelles non approuvées sont ignorées. Les actions approuvées deviennent app_* au prochain tools/list, et immédiatement invocables par cg_app_catalog/cg_app_invoke. L'authentification OAuth exige app:read et app:write pour écrire, en plus des droits locaux. Le module Contacts.php fourni est un exemple de CRM privé : liste, création, modification, schémas contrôlés et requêtes préparées. Sa base SQLite n'est créée qu'au premier ajout. Aucun accès SQL arbitraire n'est exposé. L'API éditoriale et les outils cms_* existants sont préservés. Le mode OAuth utilise les autorisations éditoriales MCP existantes.

### Limites et sécurité
Il s'agit d'un prototype sur installation individuelle, pas d'une sandbox. Du PHP approuvé s'exécute avec les droits OS du compte : il peut donc lire des secrets, d'autres applications du même compte ou modifier des fichiers malgré l'allowlist CodeGraft. Pour un service hébergé multi-membres, imposer une isolation OS/conteneur et ne jamais activer de PHP arbitraire sur le compte partagé du Hub. Pas de route HTTP publique métier personnalisée à ce stade, ni migrations automatiques de données ; le rollback de code ne restaure pas SQLite. Pas de connexion en un clic Hub encore. Les tests réels d'OAuth, scopes et révocation doivent être effectués sur le sous-domaine de test. Le test CLI `php tests/business.php "$HOME/tipi_cms"` utilise exclusivement des données temporaires. L'administration unifiée présente les droits, les versions, les modules et les connexions OAuth.

### Distribution embarquée
`php scripts/verify-codegraft.php` contrôle le paquet local. `bash scripts/install-codegraft.sh` ne clone plus le dépôt privé Framework. La récupération de Tipi CMS lui-même reste nécessaire pour obtenir cette version. La procédure CLI ne réinstalle pas le blog. Le fichier PHP autonome V0.1 n'embarque pas encore le paquet complet : pour activer CodeGraft, utiliser la distribution complète.

## V0.4 : administration unique
Pour les installations existantes : `bash scripts/update.sh "$HOME/tipi_cms/public"` met à jour le noyau et l'adaptateur, avec sauvegardes. Ne pas réinstaller CodeGraft. Les anciens identifiants CodeGraft ne sont plus utilisés : l'ancienne URL redirige vers Assistant IA, et OAuth s'appuie sur la session du CMS. Les nouvelles installations de CodeGraft ne demandent aucun mot de passe supplémentaire. Sélectionner Consultation (défaut), Gestion du site, ou Création de fonctionnalités (consentement explicite au risque PHP). Les droits OAuth restent nécessaires. Le développement se limite aux fichiers PHP autonomes app/McpActions ; les commandes et le déploiement direct par MCP sont bloqués. Le design public se modifie via cms_get_design/cms_update_design : palettes, dispositions et CSS personnalisé, historique et restauration dans Apparence. Il n'y a pas encore de système de templates HTML arbitraires ni d'interfaces web métier. Les clés techniques existantes restent distinctes ; les profils pilotent l'accès OAuth. Le Hub reste à connecter.
