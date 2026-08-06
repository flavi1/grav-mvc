<?php

namespace Grav\Plugin\Mvc;

use Grav\Common\Grav;

/**
 * Contrôleur abstrait de base pour la couche MVC.
 *
 * Articulation générale :
 *   Request -> controller -> Response -> mvc.php (A5)
 *
 *  - `Request` (A6) encapsule la requête HTTP entrante ; le contrôleur y accède
 *    via `request()` et ne lit JAMAIS les superglobales directement.
 *  - Chaque méthode d'action publique du contrôleur RETOURNE une `Response`
 *    (A4), qui porte tout l'état de sortie (template, contentType, httpCode,
 *    content, twigVars, redirection). Il n'existe PAS de méthode `render()`.
 *  - La couche d'intégration (`mvc.php`, A5) exploite la `Response` pour créer
 *    la page virtuelle Grav ou émettre une redirection.
 *
 * Le contrôleur ne fait donc JAMAIS de rendu Twig, n'écrit JAMAIS d'en-tête et
 * ne fait JAMAIS `exit`.
 */
abstract class AbstractController
{
    /**
     * Conteneur Grav.
     *
     * @var Grav
     */
    protected Grav $grav;

    /**
     * Requête courante (résolue paresseusement via request()).
     *
     * @var Request|null
     */
    protected ?Request $request;

    /**
     * Session.
     *
     * @var Session
     */
    protected $session;

    /**
     * Construit le contrôleur.
     *
     * @param Grav|null    $grav    Conteneur Grav (résolu via Grav::instance()
     *                              si null).
     * @param Request|null $request Requête courante (injectable pour les tests ;
     *                              résolue à la demande si null).
     */
    public function __construct(?Grav $grav = null, ?Request $request = null)
    {
        $this->grav    = $grav ?? Grav::instance();
        $this->request = $request;
        $this->session = $grav['session'];
    }

    // ------------------------------------------------------------------
    // Accès à la requête
    // ------------------------------------------------------------------

    /**
     * Retourne la requête courante, en la construisant si nécessaire.
     *
     * @return Request
     */
    protected function request(): Request
    {
        if ($this->request === null) {
            $this->request = Request::fromGrav($this->grav);
        }

        return $this->request;
    }

    /**
     * Indique si la requête courante est de type POST.
     *
     * @return bool
     */
    protected function isPost(): bool
    {
        return $this->request()->isPost();
    }

    /**
     * Récupère une valeur filtrée des paramètres POST.
     *
     * @param string $key     Clé recherchée.
     * @param int    $filter  Filtre PHP (FILTER_*).
     * @param mixed  $default Valeur par défaut.
     *
     * @return mixed
     */
    protected function post(string $key, int $filter = FILTER_DEFAULT, $default = null)
    {
        return $this->request()->input($key, $filter, $default);
    }

    /**
     * Récupère une valeur filtrée des paramètres GET.
     *
     * @param string $key     Clé recherchée.
     * @param int    $filter  Filtre PHP (FILTER_*).
     * @param mixed  $default Valeur par défaut.
     *
     * @return mixed
     */
    protected function get(string $key, int $filter = FILTER_DEFAULT, $default = null)
    {
        return $this->request()->query($key, $filter, $default);
    }

    // ------------------------------------------------------------------
    // Helpers d'environnement
    // ------------------------------------------------------------------

    /**
     * Retourne la route courante.
     *
     * @return string
     */
    protected function route(): string
    {
        return (string) $this->grav['uri']->route();
    }

    /**
     * Retourne la configuration d'un plugin.
     *
     * @param string $pluginName Nom du plugin (ex. 'simple-shop').
     *
     * @return array
     */
    protected function pluginConfig(string $pluginName): array
    {
        return (array) $this->grav['config']->get('plugins.' . $pluginName, []);
    }

    /**
     * Construit une URL absolue à partir d'une route relative.
     *
     * @param string $route Route relative (ex. '/cart'). Vide => accueil.
     *
     * @return string
     */
    protected function absoluteUrl(string $route = ''): string
    {
        $base = $this->grav['base_url_absolute'] ?? '';

        if ($route === '' || $route === '/') {
            return $base !== '' ? $base : '/';
        }

        return $base . $route;
    }

    // ------------------------------------------------------------------
    // Fabriques de réponse
    // ------------------------------------------------------------------

    /**
     * Crée une nouvelle Response vierge liée au conteneur Grav.
     *
     * @return Response
     */
    protected function response(): Response
    {
        return new Response($this->grav);
    }

    /**
     * Crée une Response de contenu HTML brut (sans template).
     *
     * @param string $content Corps HTML.
     * @param int    $code    Code de statut HTTP.
     *
     * @return Response
     */
    protected function html(string $content, int $code = 200): Response
    {
        return $this->response()
            ->setContentType('text/html')
            ->setContent($content)
            ->setHttpCode($code);
    }

    /**
     * Crée une Response JSON.
     *
     * @param array $data Données à sérialiser.
     * @param int   $code Code de statut HTTP.
     *
     * @return Response
     */
    protected function json(array $data, int $code = 200): Response
    {
        return $this->response()
            ->setContentType('application/json')
            ->setContent((string) json_encode($data))
            ->setHttpCode($code);
    }

    /**
     * Crée une Response de redirection.
     *
     * @param string $url  URL cible.
     * @param int    $code Code de statut HTTP.
     *
     * @return Response
     */
    protected function redirect(string $url, int $code = 302): Response
    {
        return $this->response()->setRedirect($url, $code);
    }

    /**
     * Crée une Response « page virtuelle » (template + variables Twig).
     *
     * Cas nominal du tunnel : le contenu HTML est produit par le template Grav
     * (convention Zpip), le contrôleur se contente de fournir les variables.
     *
     * @param string $templateName Nom du template Grav (ex. 'simple-shop/step1cart').
     * @param array  $twigVars     Variables à injecter dans Twig.
     * @param int    $code         Code de statut HTTP.
     *
     * @return Response
     */
    protected function view(string $templateName, array $twigVars = [], int $code = 200): Response
    {
        return $this->response()
            ->setTemplate($templateName)
            ->setTwigVars($twigVars)
            ->setHttpCode($code);
    }

    // ------------------------------------------------------------------
    // Messages flash
    // ------------------------------------------------------------------

    /**
     * Émet un message flash sur le canal `public_messages`.
     *
     * S'appuie sur `NoJs::addMessage()` (canal déjà fonctionnel, affiché par le
     * partial header_navigation.html.twig). L'appel est protégé pour ne pas
     * coupler durement le plugin MVC au thème NoJs.
     *
     * @param string $message Message à afficher.
     * @param string $scope   Portée/type ('info', 'success', 'error', ...).
     *
     * @return void
     */
    protected function addMessage(string $message, string $scope = 'info'): void
    {
        if (class_exists(\Grav\Theme\NoJs::class)
            && method_exists(\Grav\Theme\NoJs::class, 'addMessage')) {
            \Grav\Theme\NoJs::addMessage($message, $scope);
        }
    }
}
