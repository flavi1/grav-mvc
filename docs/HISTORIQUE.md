# Historique — ce qui a été délibérément fait (mvc)

> Repris le 15/10/2026 de `private/prompts/DONE.md` du site d'origine (senonevero.flavieng.com), en ne gardant que ce qui concerne
> ce plugin. « Preuve » = fichier, test ou procédure qui le montre. Les numéros (A1, T4, O6, Q9…) sont ceux des anciens fichiers de
> suivi du site ; ils ne renvoient à rien dans ce dépôt mais sont gardés pour pouvoir retrouver une discussion.

## Phase A — Plugin `mvc`

| Tâche | État | Preuve |
|-------|------|--------|
| A1 Squelette du plugin | Fait | `mvc/mvc.php`, `mvc.yaml`, `blueprints.yaml`, PSR-4 `Grav\Plugin\Mvc\` |
| A2 `RouteManager` | Fait | `add`, `addMany`, `match`, `all`, `routes` ; exceptions si classe/méthode absente |
| A3 `AbstractController` | Fait | helpers `request/route/pluginConfig/absoluteUrl`, fabriques `html/json/redirect/view`, `addMessage` |
| A4 `Response` | Fait | setters/getters chaînables, `html/json/redirect/template`, `isRedirect`, `isRawOutput`, `useDefaultLayout` (ajout) |
| A5 Page virtuelle | Fait | `mvc.php` + `PageFactory` ; exécution unique du contrôleur ; redirection et sortie brute gérées |
| A6 `Request` | Fait | `fromGlobals/fromPsr/fromGrav`, `query()/input()` filtrés |

Ajout non prévu : événement `onMvcRegisterRoutes` (le contrôleur ne s'exécute plus
à `onPluginsInitialized` mais à `onPageInitialized`, pour que tous les services —
dont `Email` — soient prêts).

## Évolutions ultérieures

| Tâche | État | Preuve |
|---|---|---|
| Webhook Stripe | Oui (à brancher) | Route `/stripe/webhook`, événement `payment_intent.succeeded`, signature vérifiée, secret `webhook_secret` ; instantané de commande `pending_payment` enregistré au démarrage du paiement ; `OrderFinalizer` partagé avec le retour client (idempotent, e-mails une seule fois) ; le plugin `mvc` expose `Request::getRawBody()` |
| T4 `CartController` conforme au contrat MVC : 19 `redirect(); exit;` → `return $this->redirect()`, 23 `filter_input`/`$_POST`/`$_GET`/`$_SERVER` → `Request` (`post()`, `get()`, `isPost()`, `postString()` pour refuser les tableaux forgés) | Fait | `tests/checkout-flow.php` : tunnel complet, chaque étape retourne une `Response` |
| T5 `AbstractController` : session lue sur `$this->grav` (et non sur le paramètre nullable) | Fait | `mvc/classes/AbstractController.php` |
| T11 / O6 `PageFactory` : code mort retiré ; `path` = dossier du plugin (justifié) ; `addPage()` reste dans mvc.php (justifié) | Fait | `mvc/classes/PageFactory.php` |
