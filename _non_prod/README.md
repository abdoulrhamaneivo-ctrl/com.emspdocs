# _non_prod

Ce dossier regroupe les fichiers et outils qui ne sont pas necessaires au runtime de la plateforme.

Contenu deplace ici :
- `docs/` : cahier des charges, guides et rapports
- `archives/` : exports et archives ponctuelles
- `qa/` : outils Playwright, `package.json`, `package-lock.json`, `node_modules`
- `tools/` : migrations SQL, outils CLI et scripts de maintenance

Important :
- ce dossier est protege par `.htaccess`
- il ne doit pas etre expose publiquement
- il n'est pas necessaire au fonctionnement quotidien du site

Fichiers volontairement gardes a la racine :
- `vendor/` : requis en production
- `composer.json` / `composer.lock` : gardes pour la maintenance Composer future
- `.env` et `php.ini` : utilises par la configuration de l'application ou de l'hebergement


