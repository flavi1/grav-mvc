# Plugin MVC pour Grav

Le plugin **MVC** apporte à Grav une couche « routes -> contrôleur » simple et
réutilisable. Vous associez une route (chemin d'URL) à une méthode de contrôleur.
Cette méthode reçoit la requête, exécute votre logique métier et retourne une
`Response`. Le plugin se charge ensuite de produire la page Grav correspondante
ou d'effectuer une redirection.

## Installation

1. Placez le plugin dans `user/plugins/mvc/` (dépôt : https://github.com/flavi1/grav-mvc ; sur l'hébergement
   mutualisé : `/shared/grav-2/user/plugins/mvc/`, déployé par GitHub Actions, voir `AGENTS.md`).
2. Activez-le dans la configuration du site (`user/config/plugins/mvc.yaml` : `enabled: true`) : il est
   **désactivé par défaut**, le dossier des plugins pouvant être partagé par plusieurs sites.
3. Déclarez la dépendance dans le plugin qui l'utilise afin de garantir l'ordre
   de chargement.

## Concepts

Le plugin repose sur quatre classes, toutes dans le namespace `Grav\Plugin\Mvc` :

| Classe               | Rôle                                                                     |
|----------------------|--------------------------------------------------------------------------|
| `RouteManager`       | Table d'association route -> [classe, méthode].                          |
| `Request`            | Encapsule la requête HTTP entrante (méthode, GET, POST, ...).            |
| `Response`           | Décrit ce que le contrôleur veut renvoyer (page, contenu, redirection).  |
| `AbstractController` | Classe de base à étendre pour écrire vos contrôleurs.                    |

Le service `mvc_router` (une instance de `RouteManager`) est exposé dans le
conteneur Grav et sert à enregistrer vos routes.

## Démarrage rapide

### 1. Enregistrer vos routes

Depuis votre propre plugin, pendant `onPluginsInitialized`, récupérez le service
`mvc_router` et déclarez vos routes. Chaque route pointe vers un couple
`[ClasseController::class, 'nomDeLaMethode']`.

    public function onPluginsInitialized(): void
    {
        /** @var \Grav\Plugin\Mvc\RouteManager $router */
        $router = $this->grav['mvc_router'];

        $router->addMany([
            '/cart'              => [\Vendor\Controllers\CartController::class, 'index'],
            '/checkout/delivery' => [\Vendor\Controllers\CartController::class, 'delivery'],
        ]);
    }

> Enregistrez vos routes **avant** que le plugin MVC ne résolve la route
> courante. En pratique, abonnez-vous à `onPluginsInitialized` avec une priorité
> inférieure à celle du plugin MVC (voir « Ordre de chargement »).

`RouteManager` vérifie, dès l'enregistrement, que la classe et la méthode
existent ; sinon une `\InvalidArgumentException` est levée.

### 2. Écrire un contrôleur

Étendez `AbstractController` et écrivez une méthode d'action par route. Une
méthode d'action retourne toujours un objet `Response`.

    namespace Vendor\Controllers;

    use Grav\Plugin\Mvc\AbstractController;
    use Grav\Plugin\Mvc\Response;

    class CartController extends AbstractController
    {
        public function index(): Response
        {
            if ($this->isPost()) {
                $email = $this->post('email', FILTER_VALIDATE_EMAIL);
                // ... votre logique métier ...

                return $this->redirect($this->absoluteUrl('/checkout/delivery'));
            }

            return $this->view('mon-plugin/cart', [
                'items' => [/* ... */],
            ]);
        }
    }

## Écrire des contrôleurs

### Lire la requête

Les helpers suivants sont disponibles depuis un contrôleur :

| Méthode                         | Description                                    |
|---------------------------------|------------------------------------------------|
| `request(): Request`            | Objet requête complet.                         |
| `isPost(): bool`                | Vrai si la requête est un POST.                |
| `post($clef, $filtre, $defaut)` | Valeur POST filtrée (filtres PHP `FILTER_*`).  |
| `get($clef, $filtre, $defaut)`  | Valeur GET filtrée.                            |
| `route(): string`               | Route courante.                                |

Pour un contrôle plus fin, utilisez directement l'objet `Request` :
`getMethod()`, `getQueryParams()`, `getParsedBody()`, `getServerParam()`, etc.

### Produire une réponse

Utilisez l'une des fabriques fournies par `AbstractController` :

| Méthode                                     | Résultat                                         |
|---------------------------------------------|--------------------------------------------------|
| `view($template, $vars = [], $code = 200)`  | Rend une page Grav via un template + variables.  |
| `html($contenu, $code = 200)`               | Renvoie du contenu HTML brut.                    |
| `json($donnees, $code = 200)`               | Renvoie une réponse JSON.                        |
| `redirect($url, $code = 302)`               | Effectue une redirection HTTP.                   |
| `response(): Response`                      | Réponse vierge à configurer manuellement.        |

L'objet `Response` peut aussi être configuré à la main (setters chaînables) :

    return $this->response()
        ->setTemplate('mon-plugin/facture')
        ->setTwigVar('order', $order)
        ->setHttpCode(200);

### Passer des variables à un template

Les variables fournies à `view()` (ou via `setTwigVar()` / `setTwigVars()`)
sont injectées dans le contexte du template Grav de la page produite.

### Titre et métadonnées de la page

La page produite reçoit le titre donné par le contrôleur, sinon un titre tiré de la route (`/mon-compte` → « Mon compte »).
Les balises `<meta>` passent par l'en-tête Grav `metadata` (affiché par le thème comme pour une page ordinaire) :

```php
return $this->view('shop/cart', $vars)
    ->setTitle('Votre panier')
    ->setDescription('Vérifiez vos articles avant de commander.')
    ->setMeta('robots', 'noindex, nofollow');     // pages privées : pas d'indexation
```

`setPageHeader([...])` ajoute d'autres en-têtes de page Grav (ex. `body_classes`).

### Sortie brute (sans layout du thème)

Par défaut, une réponse de **contenu brut** (`html()`) est tout de même
enveloppée par le layout du thème (header, footer, zones « Zpip »), car son
contenu est injecté dans une page virtuelle Grav.

Pour émettre un contenu **nu**, tel quel, sans aucun chrome du site, désactivez
le layout via `useDefaultLayout(false)` sur la `Response`. La couche
d'intégration (`mvc.php`) émet alors directement le corps de la réponse (avec
son `Content-Type` et son code HTTP) puis termine la requête.

    // Exemple : page technique d'auto-soumission (POST -> étape suivante),
    // que l'on ne veut PAS enrober du header/footer du site.
    public function confirm(): Response
    {
        $html  = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>";
        $html .= "<form id='f' method='POST' action='/suite'>";
        $html .= "<input type='hidden' name='order_id' value='42'>";
        $html .= "</form><script>document.getElementById('f').submit();</script>";
        $html .= "</body></html>";

        return $this->html($html)->useDefaultLayout(false);
    }

Points importants :

- `useDefaultLayout()` n'a d'effet que pour les réponses **sans template**
  (contenu brut). Une réponse avec template (`view()`) est toujours rendue par
  Grav et enveloppée par le thème.
- Le prédicat `Response::isRawOutput()` vaut `true` lorsqu'il n'y a pas de
  template ET que le layout est désactivé ; c'est ce qui déclenche l'émission
  directe.
- Les réponses **JSON** (`json()`) sont en sortie brute **par défaut** : il est
  inutile d'appeler `useDefaultLayout(false)`, on ne veut jamais enrober du JSON
  dans le layout HTML.
- La sortie brute est émise très tôt dans le cycle Grav (pendant
  `onPluginsInitialized`) et termine la requête. Le contenu doit donc être
  autonome (document complet ou charge utile technique).

### Autres helpers

| Méthode                                 | Description                                        |
|-----------------------------------------|----------------------------------------------------|
| `pluginConfig($nomPlugin): array`       | Configuration d'un plugin (`plugins.<nom>`).       |
| `absoluteUrl($route = ''): string`      | Construit une URL absolue depuis une route.        |
| `addMessage($message, $scope = 'info')` | Ajoute un message flash à afficher à l'utilisateur.|

## Types de réponses

- **Page (template)** : lorsqu'un template est défini (`view()` /
  `setTemplate()`), le plugin crée une page Grav routée sur l'URL courante et lui
  affecte ce template. Le rendu est produit par Grav (et enveloppé par le thème).
- **Contenu brut enveloppé** : `html()` renvoie un corps de réponse sans
  template ; par défaut ce corps est injecté dans une page virtuelle et donc
  **enveloppé par le layout du thème**.
- **Contenu brut nu** : `html(...)->useDefaultLayout(false)` (ou `json()`) émet
  le corps **tel quel**, sans layout ni chrome du site, avec le `Content-Type`
  adapté.
- **Redirection** : `redirect()` déclenche une redirection HTTP vers l'URL cible.

## Ordre de chargement

Le plugin MVC s'initialise avec une priorité élevée pour exposer le service
`mvc_router` tôt, puis résout la route courante. Vos plugins doivent donc
**enregistrer leurs routes avant cette résolution** : abonnez-vous à
`onPluginsInitialized` avec une priorité inférieure à celle du plugin MVC, et
déclarez le plugin MVC comme dépendance.

## Référence des classes

### `RouteManager`

- `add(string $route, array $controllerClassMethod): self`
- `addMany(array $map): self`
- `match(string $route): ?array`
- `all(): array`
- `routes(): array`

### `Request`

- Fabriques : `fromGlobals()`, `fromPsr($psr)`, `fromGrav($grav)`.
- `getMethod()`, `isMethod($m)`, `isPost()`, `isGet()`
- `getQueryParams()`, `getParsedBody()`, `getServerParams()`, `getServerParam($k, $defaut)`
- `getCookieParams()`, `getUploadedFiles()`
- `query($clef, $filtre, $defaut)`, `input($clef, $filtre, $defaut)`

### `Response`

- Fabriques : `html()`, `json()`, `redirect()`, `template()`.
- Setters (chaînables) : `setTemplate()`, `setContentType()`, `setHttpCode()`
  (alias `setStatusCode()`), `setContent()` (alias `setBody()`), `setRedirect()`,
  `setTwigVar()`, `setTwigVars()`, `useDefaultLayout(bool $use = true)`, `setTitle()`, `setDescription()`,
  `setMeta()`, `setPageHeader()` (depuis la 0.2.0), `setHeader(nom, valeur)` (en-tête HTTP d'une réponse brute, ex.
  `Content-Disposition` d'un PDF ; depuis la 0.3.0).
- Getters : `getTemplate()`, `getContentType()`, `getHttpCode()`
  (alias `getStatusCode()`), `getContent()` (alias `getBody()`),
  `getRedirectUrl()`, `getTwigVars()`, `getHeaders()`, `getTitle()`, `getMeta()`, `getPageHeader()`.
- Prédicats : `hasTemplate()`, `isRedirect()`, `usesDefaultLayout()`,
  `isRawOutput()`.
- Émission : `sendHeaders()` (émet le statut + les en-têtes ; utilisé pour les
  sorties brutes et les redirections hors cycle de page).

> `useDefaultLayout(false)` marque une réponse de contenu brut comme devant être
> émise **sans** le layout du thème (voir « Sortie brute »). Sans effet sur une
> réponse avec template. `json()` désactive déjà le layout par défaut.

### `AbstractController`

- Requête : `request()`, `isPost()`, `post()`, `get()`.
- Environnement : `route()`, `pluginConfig()`, `absoluteUrl()`.
- Réponses : `response()`, `html()`, `json()`, `redirect()`, `view()`.
- Divers : `addMessage()`.

## Licence

MIT.
