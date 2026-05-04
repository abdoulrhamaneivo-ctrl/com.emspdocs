# Déploiement ProFreeHost - EMSP Docs

Ce guide couvre le déploiement du cahier de charge EMSP Docs sur un hébergement mutualisé type ProFreeHost/InfinityFree.

## 1. Préparer les fichiers

À envoyer sur l’hébergement :

- tous les fichiers PHP de la racine ;
- `admin/`, `ajax/`, `assets/`, `errors/`, `includes/`, `vendor/`, `uploads/` ;
- `.htaccess`, `manifest.json`, `sw.js`, `php.ini` si l’hébergeur l’accepte.

À ne pas exposer publiquement en production :

- `_non_prod/` sauf besoin temporaire d’import SQL ;
- `node_modules/`, `test-results/`, `.phpunit.cache/`, `.git/`, `.qodo/` ;
- fichiers `.tmp-*`, `rapport_complet.txt`, scripts QA.

## 2. Configurer l’environnement

Créer ou adapter `.env` :

```env
APP_ENV=production
APP_URL=https://votre-domaine.example

DB_HOST=sqlXXX.profreehost.com
DB_NAME=votre_base
DB_USER=votre_user
DB_PASS=votre_mot_de_passe

BREVO_API_KEY=votre_cle_brevo
BREVO_FROM_EMAIL=noreply@votre-domaine.example
BREVO_FROM_NAME="EMSP Docs"

EMSP_DEBUG_EMAIL=false
SCHOOL_EMAIL_DOMAIN=@fsmenum24.emsp.int
```

`admin/config/dbcon.php` lit la configuration et force `utf8mb4`. Ne mettez pas d’identifiants directement dans les pages métier.

## 3. Importer la base de données

Dans phpMyAdmin :

1. créer la base ;
2. sélectionner la base ;
3. importer `_non_prod/db/emsp_docs_full.sql` ;
4. appliquer ensuite les fichiers incrémentaux `_non_prod/db/2026-*.sql` seulement si votre dump de départ est ancien.

Le dump complet contient les tables du cahier de charge : utilisateurs, documents, référentiels, favoris, likes, commentaires, notifications, journal, médias, institution, domaines école, paramètres, logs email, push et historique.

## 4. Dossiers nécessaires

Créer ces dossiers si absents :

- `uploads/documents/`
- `uploads/media/`
- `uploads/media/journal/`
- `uploads/media/institution/`
- `uploads/formations/covers/`
- `uploads/profiles/`
- `uploads/student-cards/`

Permissions recommandées : `755` pour les dossiers. Éviter les `chmod()` agressifs sur hébergement mutualisé.

## 5. Vérifications après mise en ligne

- Accueil, bibliothèque, médiathèque, journal, institution et formations chargent sans erreur.
- Inscription email école : compte actif immédiatement si domaine autorisé.
- Inscription par carte : compte pending + visible dans `admin/pending-users.php`.
- Connexion étudiant, admin et modérateur.
- Dépôt document : création en `pending`.
- Validation/rejet document dans `admin/pending-documents.php`.
- Prévisualisation PDF/image/DOCX/XLSX/texte sans téléchargement automatique.
- Téléchargement uniquement via formulaire POST + CSRF.
- Favoris, likes, commentaires et réactions.
- Emails Brevo en production, debug email désactivé.
- PWA : manifest, icônes 192/512, service worker.

## 6. Commandes de maintenance locale

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never
C:\xampp\php\php.exe -l index.php
node _non_prod\qa\audit-platform.js
```

Pour Phinx :

```powershell
C:\xampp\php\php.exe vendor\bin\phinx -c phinx.php migrate
```

La configuration Phinx pointe vers `_non_prod/db/migrations`.

## 7. Dépannage

- Page blanche : vérifier logs PHP, `.env`, connexion DB.
- Caractères illisibles : vérifier import SQL en UTF-8/utf8mb4.
- Images absentes : vérifier que les fichiers référencés existent dans `uploads/`.
- Upload impossible : vérifier taille PHP, `php.ini`, permissions et limites ProFreeHost.
- Emails absents : vérifier `BREVO_API_KEY`, expéditeur Brevo, `EMSP_DEBUG_EMAIL=false`.
