<?php

namespace Grav\Plugin\Mvc;

use Grav\Common\Grav;

/**
 * Objet d'état central d'une réponse MVC.
 *
 * Chaque méthode d'action d'un contrôleur (voir AbstractController, A3)
 * retourne une instance de cette classe. Elle porte TOUT l'état nécessaire à
 * la couche d'intégration (mvc.php, A5) pour :
 *  - créer une page virtuelle Grav (cas nominal : un `template` est défini) ;
 *  - ou émettre du contenu brut (JSON, HTML sans template) ;
 *  - ou déclencher une redirection HTTP.
 *
 * L'API s'inspire de PSR-7 (nommage `getBody()`, `getStatusCode()`,
 * `getHeaders()`) SANS viser la conformité stricte : pas d'implémentation des
 * interfaces PSR, pas d'immutabilité `with*()`. Les setters sont chaînables.
 *
 * Cette classe ne fait JAMAIS de rendu Twig elle-même : le rendu HTML « page »
 * est délégué à Grav via la page virtuelle (A5).
 */
class Response
{
    /**
     * Conteneur Grav (peut être null en contexte de test / CLI).
     *
     * @var Grav|null
     */
    private ?Grav $grav;

    /**
     * Variables à injecter dans Twig (appliquées par A5 pendant
     * onTwigSiteVariables).
     *
     * @var array<string, mixed>
     */
    private array $twigVars = [];

    /**
     * Nom du template Grav de la page virtuelle (convention Zpip,
     * ex. 'simple-shop/step1cart'). `null` => pas de template (contenu brut).
     *
     * @var string|null
     */
    private ?string $template = null;

    /**
     * Type MIME de la réponse.
     *
     * @var string
     */
    private string $contentType = 'text/html';

    /**
     * Code de statut HTTP.
     *
     * @var int
     */
    private int $httpCode = 200;

    /**
     * Corps de la réponse (utilisé lorsqu'il n'y a pas de template).
     *
     * @var string
     */
    private string $content = '';

    /**
     * URL de redirection ; si non null, la réponse est une redirection.
     *
     * @var string|null
     */
    private ?string $redirectUrl = null;

    /**
     * Indique si la réponse doit être enveloppée dans le layout du thème.
     *
     * - true (défaut) : le contenu est intégré à une page virtuelle Grav et
     *   enveloppé par le thème (base.html.twig + zones Zpip).
     * - false : le contenu brut (getBody()) est émis TEL QUEL par la couche
     *   d'intégration (A5), sans layout ni chrome du site. Utile pour des
     *   sorties techniques (auto-POST, iframe, snippet, JSON HTML, ...).
     *
     * N'a d'effet que pour les réponses SANS template (contenu brut).
     *
     * @var bool
     */
    private bool $useDefaultLayout = true;

    /**
     * Titre de la page virtuelle (balise <title>, titre de page du thème). Null : titre déduit de la route.
     *
     * @var string|null
     */
    private ?string $title = null;

    /**
     * Balises <meta> de la page virtuelle (en-tête Grav `metadata`) : description, robots…
     *
     * @var array<string, string>
     */
    private array $meta = [];

    /**
     * En-têtes de page Grav supplémentaires (ex. `body_classes`), fusionnés dans la page virtuelle.
     *
     * @var array<string, mixed>
     */
    private array $pageHeader = [];

    /**
     * Construit une réponse.
     *
     * @param Grav|null $grav Conteneur Grav (résolu via Grav::instance() si
     *                        disponible, sinon null pour rester testable).
     */
    public function __construct(?Grav $grav = null)
    {
        if ($grav === null && class_exists(Grav::class)) {
            // Grav::instance() renvoie l'instance existante sans en créer une
            // nouvelle en contexte hors-Grav grâce au try/catch.
            try {
                $grav = Grav::instance();
            } catch (\Throwable $e) {
                $grav = null;
            }
        }

        $this->grav = $grav;
    }

    // ------------------------------------------------------------------
    // Fabriques statiques
    // ------------------------------------------------------------------

    /**
     * Fabrique une réponse de contenu HTML brut (sans template).
     *
     * @param string $content Corps HTML.
     * @param int    $code    Code de statut HTTP.
     *
     * @return self
     */
    public static function html(string $content, int $code = 200): self
    {
        return (new self())
            ->setContentType('text/html')
            ->setContent($content)
            ->setHttpCode($code);
    }

    /**
     * Fabrique une réponse JSON.
     *
     * @param array $data Données à sérialiser.
     * @param int   $code Code de statut HTTP.
     *
     * @return self
     */
    public static function json(array $data, int $code = 200): self
    {
        return (new self())
            ->setContentType('application/json')
            ->setContent((string) json_encode($data))
            ->setHttpCode($code)
            ->useDefaultLayout(false);
    }

    /**
     * Fabrique une réponse de redirection.
     *
     * @param string $url  URL cible.
     * @param int    $code Code de statut HTTP (302 par défaut).
     *
     * @return self
     */
    public static function redirect(string $url, int $code = 302): self
    {
        return (new self())->setRedirect($url, $code);
    }

    /**
     * Fabrique une réponse « page virtuelle » (template + variables Twig).
     *
     * @param string $templateName Nom du template Grav (convention Zpip).
     * @param array  $twigVars     Variables à injecter dans Twig.
     * @param int    $code         Code de statut HTTP.
     *
     * @return self
     */
    public static function template(string $templateName, array $twigVars = [], int $code = 200): self
    {
        return (new self())
            ->setTemplate($templateName)
            ->setTwigVars($twigVars)
            ->setHttpCode($code);
    }

    // ------------------------------------------------------------------
    // Setters (chaînables)
    // ------------------------------------------------------------------

    /**
     * Définit le nom du template de la page virtuelle.
     *
     * @param string|null $template Nom du template (null => contenu brut).
     *
     * @return self
     */
    public function setTemplate(?string $template): self
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Définit le type MIME de la réponse.
     *
     * @param string $type Type MIME (ex. 'text/html', 'application/json').
     *
     * @return self
     */
    public function setContentType(string $type): self
    {
        $this->contentType = $type;

        return $this;
    }

    /**
     * Définit le code de statut HTTP.
     *
     * @param int $code Code de statut.
     *
     * @return self
     */
    public function setHttpCode(int $code): self
    {
        $this->httpCode = $code;

        return $this;
    }

    /**
     * Alias PSR-like de setHttpCode().
     *
     * @param int $code Code de statut.
     *
     * @return self
     */
    public function setStatusCode(int $code): self
    {
        return $this->setHttpCode($code);
    }

    /**
     * Définit le corps de la réponse.
     *
     * @param string $content Corps.
     *
     * @return self
     */
    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Alias PSR-like de setContent().
     *
     * @param string $content Corps.
     *
     * @return self
     */
    public function setBody(string $content): self
    {
        return $this->setContent($content);
    }

    /**
     * Configure la réponse en redirection.
     *
     * @param string $url  URL cible.
     * @param int    $code Code de statut HTTP (302 par défaut).
     *
     * @return self
     */
    public function setRedirect(string $url, int $code = 302): self
    {
        $this->redirectUrl = $url;
        $this->httpCode    = $code;

        return $this;
    }

    /**
     * Ajoute (ou remplace) une variable Twig.
     *
     * @param string $key   Nom de la variable.
     * @param mixed  $value Valeur.
     *
     * @return self
     */
    public function setTwigVar(string $key, $value): self
    {
        $this->twigVars[$key] = $value;

        return $this;
    }

    /**
     * Fusionne un ensemble de variables Twig.
     *
     * @param array<string, mixed> $vars Variables à fusionner.
     *
     * @return self
     */
    public function setTwigVars(array $vars): self
    {
        $this->twigVars = array_merge($this->twigVars, $vars);

        return $this;
    }

    /**
     * Active ou désactive l'enveloppe par le layout du thème.
     *
     * N'a d'effet que pour les réponses SANS template (contenu brut) : quand
     * elle est désactivée, la couche d'intégration (A5) émet getBody() tel quel.
     *
     * @param bool $use true pour utiliser le layout (défaut), false pour une
     *                  sortie brute.
     *
     * @return self
     */
    public function useDefaultLayout(bool $use = true): self
    {
        $this->useDefaultLayout = $use;

        return $this;
    }

    // ------------------------------------------------------------------
    // Getters
    // ------------------------------------------------------------------

    /**
     * Retourne le nom du template (ou null).
     *
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return $this->template;
    }

    /**
     * Retourne le type MIME de la réponse.
     *
     * @return string
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Retourne le code de statut HTTP.
     *
     * @return int
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Alias PSR-like de getHttpCode().
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Retourne le corps de la réponse.
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Alias PSR-like de getContent().
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->content;
    }

    /**
     * Retourne l'URL de redirection (ou null).
     *
     * @return string|null
     */
    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    /**
     * Retourne les variables Twig.
     *
     * @return array<string, mixed>
     */
    public function getTwigVars(): array
    {
        return $this->twigVars;
    }

    /**
     * Retourne les en-têtes HTTP (au minimum le Content-Type).
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $headers = ['Content-Type' => $this->contentType] + $this->extraHeaders;

        if ($this->isRedirect()) {
            $headers['Location'] = (string) $this->redirectUrl;
        }

        return $headers;
    }

    /** En-têtes supplémentaires (nom => valeur), émis avec une réponse brute. */
    private array $extraHeaders = [];

    /**
     * Ajoute un en-tête HTTP à une réponse brute (ex. `Content-Disposition` d'un PDF, `Cache-Control`). Les retours à la
     * ligne sont refusés (injection d'en-têtes).
     */
    public function setHeader(string $name, string $value): self
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) || preg_match('/[\r\n]/', $value) || strcasecmp($name, 'Content-Type') === 0) {
            throw new \InvalidArgumentException('En-tête HTTP invalide : ' . $name);
        }
        $this->extraHeaders[$name] = $value;

        return $this;
    }

    // ------------------------------------------------------------------
    // Page virtuelle : titre et métadonnées
    // ------------------------------------------------------------------

    /** Titre de la page (balise <title> et titre affiché par le thème). */
    public function setTitle(?string $title): self
    {
        $title = $title !== null ? trim($title) : null;
        $this->title = $title === '' ? null : $title;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /** Description de la page (<meta name="description">). */
    public function setDescription(string $description): self
    {
        return $this->setMeta('description', $description);
    }

    /** Balise <meta> (ex. `robots` → `noindex, nofollow`) ; valeur vide = retirée. */
    public function setMeta(string $name, string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            unset($this->meta[$name]);
        } else {
            $this->meta[$name] = $value;
        }

        return $this;
    }

    /** @return array<string, string> */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /** En-têtes de page Grav supplémentaires (fusionnés ; `title` et `metadata` passent par setTitle()/setMeta()). */
    public function setPageHeader(array $header): self
    {
        $this->pageHeader = array_replace($this->pageHeader, $header);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPageHeader(): array
    {
        return $this->pageHeader;
    }

    // ------------------------------------------------------------------
    // Prédicats
    // ------------------------------------------------------------------

    /**
     * Indique si un template est défini.
     *
     * @return bool
     */
    public function hasTemplate(): bool
    {
        return $this->template !== null;
    }

    /**
     * Indique si la réponse est une redirection.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }

    /**
     * Indique si la réponse doit être enveloppée par le layout du thème.
     *
     * @return bool
     */
    public function usesDefaultLayout(): bool
    {
        return $this->useDefaultLayout;
    }

    /**
     * Indique si la réponse doit être émise en brut (contenu sans template ni
     * layout du thème).
     *
     * @return bool
     */
    public function isRawOutput(): bool
    {
        return !$this->hasTemplate() && $this->useDefaultLayout === false;
    }

    // ------------------------------------------------------------------
    // Émission
    // ------------------------------------------------------------------

    /**
     * Émet les en-têtes HTTP (statut + en-têtes) si possible.
     *
     * Utile pour les réponses brutes (JSON) ou les redirections traitées hors
     * du cycle de page Grav. NE crée PAS de dépendance dure à Grav : le rendu
     * HTML « page » reste géré par Grav via la page virtuelle (A5).
     *
     * @return void
     */
    public function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->httpCode);

        foreach ($this->getHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
