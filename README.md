# Tipi CMS 0.1 preview

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
Le Hub, Hector et CodeGraft MCP ne sont pas encore connectés. L'API éditoriale ne prétend pas être MCP. Le noyau reste une preview ; tests de déploiement mutualisé, sécurité avancée, médias, thèmes personnalisables, cache statique, redirections et intégration CodeGraft à réaliser. Ne pas réutiliser l'ancien mcp.php d'Oncle Web. Ne pas copier les données d'Oncle Web. Sauvegarder le dossier privé.
