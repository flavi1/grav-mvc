# Guide du dépôt — plugin Grav `mvc`

> Ce fichier est écrit pour l'agent (Claude) et pour le propriétaire. Il doit permettre de travailler sur ce plugin
> **sans accès immédiat aux autres dépôts** : contexte, décisions, historique et travail restant y sont recopiés.
> Mettre à jour les sections « À faire » et « Historique » à chaque lot de travail.

## Communication
- S'adresser au propriétaire en **français** ; code, commentaires et messages du plugin en français.
- Ne jamais reproduire de secret (clés Stripe, identifiants Colissimo, mots de passe SMTP, sel Grav). Ce dépôt est **public** :
  aucune clé, même de test, ne doit y entrer (configuration réelle : `user/config/plugins/mvc.yaml` du site, jamais versionnée).
- Ne pas supprimer de fichiers à la place du propriétaire : les lister dans « À supprimer par le propriétaire » ci-dessous.

## Rôle du plugin

Couche « route → contrôleur » pour Grav 2.0 : `RouteManager` (service `mvc_router`), `Request`, `Response`, `AbstractController`,
page virtuelle Grav produite à partir de la `Response` (`PageFactory`). Utilisé par `simple-shop` (tunnel, compte client) et
`simple-shop-payment-stripe` (webhook). Documentation d'usage : `README.md`.

- Les plugins déclarent leurs routes sur l'événement **`onMvcRegisterRoutes`**, déclenché par mvc pendant son `onPluginsInitialized`
  (priorité 1000) : s'y abonner **statiquement** (`getSubscribedEvents`) et déclarer son autoloader **dans le constructeur** du plugin,
  car `RouteManager::add()` vérifie que la classe du contrôleur existe.
- Le contrôleur s'exécute à `onPageInitialized` (une seule fois), quand tous les services (dont `Email`) sont prêts.
- `Request` : `fromGlobals/fromPsr/fromGrav`, `query()/input()` filtrés, `post()`, `get()`, `isPost()`, `postString()` (refuse les
  tableaux forgés), `getRawBody()` (webhooks signés).
- `Response` : `html/json/redirect/template`, `isRedirect`, `isRawOutput`, `useDefaultLayout`.
- `AbstractController::addMessage()` passe aujourd'hui par le thème `no-js` (`NoJs::addMessage()`) : à remplacer par le service
  `messages` de Grav (voir « À faire »).

## Famille de plugins

Extraits le 15/10/2026 du dépôt du site `senonevero.flavieng.com` (`private/grav/plugins/<nom>`), historique Git conservé
(commits ne touchant que ce dossier ; clés Stripe de test présentes dans d'anciennes versions remplacées par `CLE-STRIPE-RETIREE`).

| Dossier du plugin | Dépôt | Rôle |
|---|---|---|
| `mvc` **(ce dépôt)** | [flavi1/grav-mvc](https://github.com/flavi1/grav-mvc) | routes → contrôleurs (`Request`, `Response`, `AbstractController`) ; requis par simple-shop |
| `simple-shop` | [flavi1/grav-simple-shop](https://github.com/flavi1/grav-simple-shop) | boutique : panier, tunnel sans JavaScript, OTP, livraison, taxes, commandes, factures Factur-X, API Admin2 |
| `simple-shop-carrier-colissimo` | [flavi1/grav-simple-shop-carrier-colissimo](https://github.com/flavi1/grav-simple-shop-carrier-colissimo) | transporteur Colissimo (grille Facilité, points de retrait) |
| `simple-shop-payment-stripe` | [flavi1/grav-simple-shop-payment-stripe](https://github.com/flavi1/grav-simple-shop-payment-stripe) | paiement Stripe (formulaire intégré ou page hébergée), webhook |
| `simple-shop-payment-test` | [flavi1/grav-simple-shop-payment-test](https://github.com/flavi1/grav-simple-shop-payment-test) | passerelle de paiement simulée (sites de test) |
| — | [flavi1/senonevero.flavieng.com](https://github.com/flavi1/senonevero.flavieng.com) (privé) | site d'origine : thème `bookstore` (type de produit `book`), configuration, pages |

## Installation sur le serveur (hébergement mutualisé multi-domaines)

- Grav 2.0 est installé **une fois** dans `/shared/grav-2/` et sert plusieurs domaines (`/domains/<domaine>/private/grav` = dossier
  `user://` de chaque site ; routeurs hors de ces dépôts).
- Ce plugin est déployé dans **`/shared/grav-2/user/plugins/mvc/`** (chemin absolu chez OVH : `/home/<identifiant>/shared/…`), partagé par tous les domaines.
- Grav cherche un plugin d'abord dans le dossier du site (`user://plugins`, soit `/domains/<domaine>/private/grav/plugins/mvc`),
  **puis** dans le dossier partagé. Une copie restée dans un site masque donc la version partagée : la supprimer de ce site
  pour qu'il utilise la version partagée.
- **Désactivé par défaut**, comme tous les plugins `mvc` et `simple-shop-*` (`enabled: false` dans `mvc.yaml`), puisque le dossier est commun à tous les domaines : chaque site
  l'active dans sa propre configuration (`user/config/plugins/mvc.yaml` : `enabled: true`, ou Admin2 > Plugins).
- Fichiers publics : le routeur de domaine ne sert depuis `/user/plugins/…` que les ressources statiques (CSS, JS, images, polices) ;
  `.php`, `.yaml`, `.md`, `.json`, `.lock`… répondent 403.

## Déploiement (GitHub Actions → rsync sur SSH)

Chaque push sur `main` lance `.github/workflows/deploy.yml` (même principe que « Deploy to OVH via rsync/ssh » du site) :
1. **Contrôle** : `php -l` sur tous les fichiers PHP (hors bibliothèques tierces).
2. **Envoi** : `rsync --delete` sur SSH (mot de passe via `sshpass`) vers `/home/<identifiant>/shared/grav-2/user/plugins/mvc/`.
   Le dossier du plugin devient l'image exacte du dépôt (un fichier supprimé du dépôt disparaît du serveur), sauf `.git*`,
   `.github/`, `tests/`, `docs/`, `AGENTS.md`, jamais envoyés. Rien n'est touché en dehors de `…/plugins/mvc/`.
3. Relance à la main : onglet *Actions* → *Déploiement (rsync/ssh)* → *Run workflow* (ou *Re-run all jobs* d'une exécution).

**Mise en place (une fois par dépôt)** — GitHub → dépôt `flavi1/grav-mvc` → *Settings* → *Secrets and variables* → *Actions*.
Les noms gardent le préfixe `FTP_` (premier essai en FTP) mais ce sont les identifiants de l'hébergement, valables aussi en SSH :
- *Secrets* : `FTP_SERVER` (hôte, ex. `ftp.clusterNNN.hosting.ovh.net` ; le workflow essaie aussi `ssh.clusterNNN.hosting.ovh.net`),
  `FTP_USERNAME` (identifiant de l'hébergement), `FTP_PASSWORD`.
- *Variables* (facultatives) :
  - `FTP_PLUGINS_DIR` : chemin **absolu** du dossier des plugins partagés, avec ou sans `/` final ; par défaut
    `/home/<FTP_USERNAME>/shared/grav-2/user/plugins` (chemin d'un hébergement OVH) ;
  - `SSH_HOST` : hôte SSH s'il diffère des deux ci-dessus ; `SSH_PORT` (`22`).
- SSH doit être ouvert sur l'hébergement (offre OVH Pro ou supérieure : c'est déjà le cas pour le déploiement du site).

Vérification : le journal de l'étape « Envoi » liste les fichiers transmis ; `mvc.php` doit être présent dans le dossier.

## Conventions (reprises du site d'origine)
- Fonctionnement **sans JavaScript** côté client public (hors option Stripe « formulaire intégré ») ; JavaScript admis côté Admin2.
- Un contrôleur retourne toujours une `Response` ; pas de `exit`, pas d'accès direct à `$_POST`/`$_GET`/`$_SERVER` (passer par `Request`).
- Écriture de fichiers partagés : verrou + fichier temporaire + `rename`.
- Pas de `innerHTML` avec des données dans le JavaScript.
- Tester en ligne de commande ce qui peut l'être (`php -l`, scripts de `tests/`) ; signaler explicitement ce qui n'est testable
  qu'après déploiement (Admin2, Stripe réel, service Colissimo réel).
- Commits distincts par étape, poussés directement sur `main` (le déploiement suit).

## À faire
- [ ] **Ticket « Rendre les pages mvc disponibles au listing ? »** (repris du site, voir les tickets de ce dépôt) : injecter la page
  virtuelle dans l'index des pages de Grav (`$pages->addPage($page, $route)`, aujourd'hui dans `PageFactory`, seulement pour la page
  en cours) ; le déplacer dans `RouteManager::add()` ? Intérêt : sitemap. Pour / contre à étudier.
- [ ] **Messages flash** sans dépendance au thème `no-js` : service `messages` de Grav (étape 7 de la conception produits/prix de
  simple-shop).
- [ ] Tests en ligne de commande propres à mvc (aujourd'hui couverts indirectement par `simple-shop/tests/checkout-flow.php`).

## Historique
- `git log` : commits d'origine (messages en français, un par étape).
- `docs/HISTORIQUE.md` : ce qui a été **délibérément** fait pour ce plugin (repris de `DONE.md` du site), avec les preuves.
