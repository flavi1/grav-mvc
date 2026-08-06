<?php
namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\Mvc\PageFactory;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\Mvc\RouteManager;

/**
 * Plugin MVC.
 *
 * Apporte à Grav une couche « routes -> controller » minimaliste et
 * réutilisable (Grav n'ayant pas de MVC natif). Ce plugin ne fait aucune
 * supposition sur le métier : il fournit l'infrastructure de routage
 * (RouteManager) et le branchement sur le cycle de vie des pages Grav pour
 * créer des pages virtuelles à partir des routes enregistrées.
 *
 * Cycle :
 *  - onPluginsInitialized (priorité 1000, donc avant tous les autres
 *    plugins) : autoload PSR-4, exposition du service `mvc_router`, puis
 *    déclenchement de `onMvcRegisterRoutes` pour laisser les plugins métier
 *    (ex. simple-shop) enregistrer leurs routes ET leurs services (ex.
 *    `simple_shop_customer`). AUCUNE exécution de contrôleur n'a lieu ici.
 *  - onPageInitialized (priorité 0, hook TOUJOURS actif) : résout la route
 *    courante et exécute — une seule fois — la méthode d'action du
 *    contrôleur correspondant, puis construit la page virtuelle (ou déclenche
 *    la redirection / sortie brute) à partir de la Response obtenue.
 *  - onTwigSiteVariables (priorité 0, hook TOUJOURS actif) : applique les
 *    variables Twig portées par la Response mémorisée.
 *
 * IMPORTANT (correctif) : l'exécution du contrôleur a été déplacée de
 * `onPluginsInitialized` vers `onPageInitialized`. En effet, ce plugin
 * s'abonnait à `onPluginsInitialized` avec la priorité 1000, ce qui le fait
 * s'exécuter AVANT tous les autres plugins (priorité par défaut 0) — y
 * compris avant que ceux-ci n'aient eu l'occasion de terminer leur propre
 * `onPluginsInitialized` (par exemple, le plugin Email de Grav, qui enregistre
 * le service `'Email'` dans le conteneur à ce moment-là). Or l'ancienne
 * implémentation appelait `resolveCurrentRoute()` de façon SYNCHRONE dans ce
 * même `onPluginsInitialized`, ce qui exécutait la méthode du contrôleur (et
 * donc, par exemple, l'envoi d'un e-mail OTP) avant que ces services ne soient
 * disponibles, provoquant des échecs silencieux. En différant l'exécution du
 * contrôleur jusqu'à `onPageInitialized` (qui se déclenche après la fin
 * complète de la phase `onPluginsInitialized` de tous les plugins), on
 * garantit que tous les services enregistrés par les autres plugins sont
 * disponibles au moment où le contrôleur s'exécute.
 */
class MvcPlugin extends Plugin
{
    /**
     * Route courante résolue (si une correspondance a été trouvée).
     *
     * @var string|null
     */
    private ?string $matchedRoute = null;

    /**
     * Réponse produite par la méthode d'action (mémoïsée pour être partagée
     * entre onTwigSiteVariables et onPageInitialized, sans double exécution).
     *
     * @var Response|null
     */
    private ?Response $response = null;

    /**
     * Garde anti-double-exécution : `resolveCurrentRoute()` ne doit être
     * exécutée qu'une seule fois par requête, même si `onPageInitialized`
     * venait à être déclenché plusieurs fois par Grav.
     *
     * @var bool
     */
    private bool $resolved = false;

    /**
     * Déclare les événements Grav auxquels ce plugin s'abonne.
     *
     * `onPageInitialized` et `onTwigSiteVariables` sont désormais souscrits de
     * façon PERMANENTE (et non plus activés dynamiquement via `enable()`
     * après résolution d'une route) : chaque méthode se contente de ne rien
     * faire (garde `null`) si aucune route n'a matché.
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité haute afin que le service mvc_router et les routes
            // soient enregistrés le plus tôt possible pour les autres
            // plugins (mais SANS exécuter de contrôleur à ce stade).
            'onPluginsInitialized' => [['onPluginsInitialized', 1000]],
            'onPageInitialized'    => [['onPageInitialized', 0]],
            'onTwigSiteVariables'  => [['onTwigSiteVariables', 0]]
        ];
    }

    /**
     * Initialise le plugin : autoload PSR-4, exposition du service
     * `mvc_router`, et enregistrement des routes/services métier via
     * `onMvcRegisterRoutes`.
     *
     * Ne résout NI n'exécute la route courante : voir {@see self::onPageInitialized()}.
     *
     * @return void
     */
    public function onPluginsInitialized(): void
    {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = $this->grav['loader'];
        $loader->addPsr4('Grav\\Plugin\\Mvc\\', __DIR__ . '/classes/');

        if ($this->isAdmin()) {
            return;
        }

        // Service partagé : une seule instance de RouteManager pour toute
        // la requête, accessible via $grav['mvc_router'].
        $this->grav['mvc_router'] = function () {
            return new RouteManager();
        };

        // Laisse les plugins métier enregistrer leurs routes ET leurs
        // services (ex. simple_shop_customer). Aucune exécution de
        // contrôleur ne doit avoir lieu ici (voir onPageInitialized).
        $this->grav->fireEvent('onMvcRegisterRoutes');
    }

    /**
     * Résout la route courante (une seule fois) et, si elle correspond à une
     * route enregistrée, exécute la méthode d'action du contrôleur puis
     * construit la page virtuelle correspondante (ou déclenche la
     * redirection / sortie brute).
     *
     * Exécuté à `onPageInitialized`, c'est-à-dire APRÈS la fin complète de la
     * phase `onPluginsInitialized` de tous les plugins : tous les services
     * qu'ils enregistrent (ex. le service `'Email'` de Grav) sont donc déjà
     * disponibles lorsque le contrôleur s'exécute.
     *
     * @return void
     */
    public function onPageInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->resolveCurrentRoute();

        if ($this->response === null || $this->matchedRoute === null) {
            return;
        }

        $response = $this->response;

        // Transcription de l'état HTTP (statut + content type) de la Response.
        if (!headers_sent()) {
            http_response_code($response->getHttpCode());
            header('Content-Type: ' . $response->getContentType());
        }

        // Création de la page virtuelle.
        $factory = new PageFactory($this->grav);
        $page = $factory->createFromResponse($response, $this->matchedRoute);

        /** @var \Grav\Common\Page\Pages $pages */
        $pages = $this->grav['pages'];
        $pages->addPage($page, $this->matchedRoute);

        unset($this->grav['page']);
        $this->grav['page'] = $page;
    }

    /**
     * Tente de résoudre la route courante via le RouteManager. Si une route
     * matche, appelle (une seule fois) la méthode d'action, mémorise la
     * Response obtenue, et traite immédiatement les cas « redirection » et
     * « sortie brute ».
     *
     * @return void
     */
    private function resolveCurrentRoute(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        /** @var RouteManager $router */
        $router = $this->grav['mvc_router'];

        $currentRoute = (string) $this->grav['uri']->route();
        $match = $router->match($currentRoute);

        if ($match === null) {
            return;
        }

        $class  = $match['class'];
        $method = $match['method'];

        /** @var \Grav\Plugin\Mvc\AbstractController $controller */
        $controller = new $class($this->grav);

        // Appel UNIQUE de la méthode d'action (effets de bord POST maîtrisés).
        $response = $controller->$method();

        if (!$response instanceof Response) {
            // Contrat non respecté : on ignore silencieusement plutôt que de
            // casser le rendu de la page.
            return;
        }

        $this->matchedRoute = $currentRoute;
        $this->response     = $response;

        // Redirection : on la traite immédiatement (Grav gère l'exit), sans
        // attendre la construction d'une page virtuelle.
        if ($response->isRedirect()) {
            $this->grav->redirect(
                (string) $response->getRedirectUrl(),
                $response->getHttpCode()
            );
            return;
        }

        // Sortie brute (contenu sans template ET layout désactivé) : on émet
        // directement le corps, sans layout ni chrome du site.
        if ($response->isRawOutput()) {
            $this->sendRawResponse($response);
            return;
        }
    }

    /**
     * Émet une réponse brute (sans template ni layout du thème) puis termine
     * la requête.
     *
     * @param Response $response Réponse à émettre.
     *
     * @return void
     */
    private function sendRawResponse(Response $response): void
    {
        $response->sendHeaders();

        echo $response->getBody();

        exit;
    }

    /**
     * Applique les variables Twig portées par la Response, si une route a
     * matché et produit une réponse (page virtuelle avec template).
     *
     * @return void
     */
    public function onTwigSiteVariables(): void
    {
        if ($this->response === null) {
            return;
        }

        /** @var \Grav\Common\Twig\Twig $twig */
        $twig = $this->grav['twig'];

        foreach ($this->response->getTwigVars() as $key => $value) {
            $twig->twig_vars[$key] = $value;
        }
    }
}
