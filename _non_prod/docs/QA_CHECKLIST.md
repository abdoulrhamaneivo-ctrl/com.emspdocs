# Checklist QA - EMSP Docs

Cette checklist reprend les critères d’acceptation du cahier de charge.

## Front-office

- [ ] `index.php` charge sans erreur PHP/JS visible.
- [ ] `institution.php` affiche les blocs dynamiques sans image cassée.
- [ ] `formations.php` affiche les filières actives, résumés, niveaux et placeholders si visuel absent.
- [ ] `bibliotheque.php` filtre par recherche, filière, licence, module, matière, semestre et type.
- [ ] `document.php?id=...` affiche détail, preview, likes, favoris, commentaires.
- [ ] `mediatheque.php` affiche albums, vidéos/liens et lightbox sans ressource cassée.
- [ ] `news-blog.php` et `news-article.php` affichent annonces, sondages et défis.
- [ ] `concours.php` affiche uniquement les concours/examens autorisés.
- [ ] `faq.php` reste lisible sur mobile.

## Authentification et comptes

- [ ] Inscription email école : domaine autorisé, compte `active`, login immédiat.
- [ ] Inscription carte : upload JPG/PNG/PDF, compte `pending`, email vérification.
- [ ] `verify-email.php` confirme l’email puis garde le compte en attente admin si nécessaire.
- [ ] Login crée `auth`, `auth_user`, `auth_role`.
- [ ] Logout détruit la session.
- [ ] Reset mot de passe : token, expiration, nouveau mot de passe >= 8 caractères.
- [ ] Comptes `pending`, `rejected`, `suspended` sont redirigés proprement.

## Documents

- [ ] Dépôt document avec CSRF, taille, MIME réel, hash anti-doublon.
- [ ] Document déposé en `pending`, invisible publiquement avant approbation.
- [ ] Admin/modérateur approuve/rejette avec motif.
- [ ] Preview PDF/image/DOCX/XLSX/texte fonctionne sans téléchargement automatique.
- [ ] `telecharger.php?download=1` refuse GET et exige POST + CSRF.
- [ ] Refresh de page ne relance jamais un téléchargement.

## Interactions

- [ ] Favori add/remove via AJAX et affichage dans `mes-favoris.php`.
- [ ] Like/unlike document met à jour le compteur et notifie l’auteur.
- [ ] Commentaires et réponses sont échappés et modérables.
- [ ] Réactions commentaire via `reaction-handler.php`.
- [ ] Notifications lues/non lues et sections nav synchronisées.

## Admin

- [ ] `admin/index.php` dashboard accessible aux rôles autorisés.
- [ ] `admin/pending-users.php` valide/rejette les comptes carte.
- [ ] `admin/pending-documents.php` preview + approve/reject.
- [ ] `admin/view-documents.php` gère tous les documents.
- [ ] `admin/view-users.php`, `add-user.php`, `edit-user.php` gèrent les comptes.
- [ ] Filières : `view-filieres.php`, `manage-filiere.php`, `add-filiere.php`.
- [ ] Licences, modules, matières : ajout, édition, activation/désactivation.
- [ ] Journal : articles, sondages, défis, dates de publication.
- [ ] Médiathèque : catégories, médias, posters vidéo.
- [ ] Institution : blocs texte/image/galerie/stats/citation/colonnes/séparateur.
- [ ] Paramètres : bannière, seuil badge Or, clés VAPID.
- [ ] Domaines email école : CRUD actif/inactif.

## UX, sécurité, performance

- [ ] Aucun débordement horizontal réel sur mobile.
- [ ] Boutons tactiles >= 40px.
- [ ] Inputs mobiles >= 16px.
- [ ] Aucun mojibake visible.
- [ ] Bootstrap et Bootstrap Icons servis localement.
- [ ] CSRF sur actions POST.
- [ ] IDs numériques validés serveur.
- [ ] Chemins fichiers contrôlés par `realpath`/racine autorisée.
- [ ] Listes paginées ou limitées.
- [ ] Index critiques présents dans `_non_prod/db/emsp_docs_full.sql`.

## Commandes

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never

$files = rg --files -g "*.php" -g "!vendor/**" -g "!node_modules/**" -g "!_non_prod/qa/node_modules/**"
foreach($f in $files){ C:\xampp\php\php.exe -l $f }

$env:EMSP_BROWSER_CHANNEL='msedge'
node _non_prod\qa\audit-platform.js
```
