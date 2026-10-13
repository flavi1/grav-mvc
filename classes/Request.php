<?php

namespace Grav\Plugin\Mvc;

/**
 * Encapsulation de la requête HTTP entrante.
 *
 * Cette classe centralise l'accès aux données de requête ($_SERVER, $_GET,
 * $_POST) afin de sortir cette responsabilité des contrôleurs et de les rendre
 * testables (on peut injecter une Request fabriquée à la main).
 *
 * L'API s'inspire de PSR-7 (`ServerRequestInterface`) SANS implémenter
 * l'interface ni viser la conformité stricte (pas d'immutabilité `with*()`,
 * pas de `UriInterface`/`StreamInterface`). Cohérent avec la ligne retenue
 * pour Response (A4).
 *
 * Grav fournit déjà un `Psr\Http\Message\ServerRequestInterface` via
 * `$grav['request']` : quand il est disponible, on s'appuie dessus
 * (voir fromGrav/fromPsr) ; sinon on retombe sur les superglobales
 * (fromGlobals), utile en test / CLI.
 */
class Request
{
    /**
     * Méthode HTTP en MAJUSCULES (ex. 'GET', 'POST').
     *
     * @var string
     */
    private string $method;

    /**
     * Paramètres de requête (équivalent de $_GET / getQueryParams()).
     *
     * @var array
     */
    private array $queryParams;

    /**
     * Corps analysé (équivalent de $_POST / getParsedBody()).
     *
     * @var array
     */
    private array $parsedBody;

    /**
     * Paramètres serveur (équivalent de $_SERVER / getServerParams()).
     *
     * @var array
     */
    private array $serverParams;

    /**
     * Cookies (équivalent de $_COOKIE / getCookieParams()).
     *
     * @var array
     */
    private array $cookies;

    /**
     * Fichiers téléversés (équivalent de $_FILES / getUploadedFiles()).
     *
     * @var array
     */
    private array $uploadedFiles;

    /**
     * Corps brut de la requête (webhooks : la signature porte sur les octets
     * exacts reçus). Null = pas encore lu (lecture paresseuse de php://input).
     *
     * @var string|null
     */
    private ?string $rawBody;

    /**
     * Construit une requête.
     *
     * @param string $method       Méthode HTTP (normalisée en MAJUSCULES).
     * @param array  $queryParams  Paramètres GET.
     * @param array  $parsedBody   Paramètres POST.
     * @param array  $serverParams Paramètres serveur.
     * @param array  $cookies      Cookies.
     * @param array  $uploadedFiles Fichiers téléversés.
     */
    public function __construct(
        string $method,
        array $queryParams = [],
        array $parsedBody = [],
        array $serverParams = [],
        array $cookies = [],
        array $uploadedFiles = [],
        ?string $rawBody = null
    ) {
        $this->method        = strtoupper($method);
        $this->queryParams   = $queryParams;
        $this->parsedBody    = $parsedBody;
        $this->serverParams  = $serverParams;
        $this->cookies       = $cookies;
        $this->uploadedFiles = $uploadedFiles;
        $this->rawBody       = $rawBody;
    }

    // ------------------------------------------------------------------
    // Fabriques
    // ------------------------------------------------------------------

    /**
     * Construit une requête à partir des superglobales PHP.
     *
     * Seul point de la classe autorisé à lire directement les superglobales.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE ?? [],
            $_FILES ?? []
        );
    }

    /**
     * Construit une requête à partir d'une requête PSR-7 (Grav).
     *
     * @param \Psr\Http\Message\ServerRequestInterface $psr Requête PSR-7.
     *
     * @return self
     */
    public static function fromPsr(\Psr\Http\Message\ServerRequestInterface $psr): self
    {
        $parsedBody = $psr->getParsedBody();
        if (is_object($parsedBody)) {
            $parsedBody = (array) $parsedBody;
        } elseif (!is_array($parsedBody)) {
            $parsedBody = [];
        }

        return new self(
            $psr->getMethod(),
            $psr->getQueryParams(),
            $parsedBody,
            $psr->getServerParams(),
            $psr->getCookieParams(),
            $psr->getUploadedFiles(),
            (string) $psr->getBody()
        );
    }

    /**
     * Construit une requête à partir du conteneur Grav.
     *
     * Si `$grav['request']` existe et est une requête PSR-7, on l'utilise ;
     * sinon on retombe sur les superglobales.
     *
     * @param \Grav\Common\Grav $grav Conteneur Grav.
     *
     * @return self
     */
    public static function fromGrav(\Grav\Common\Grav $grav): self
    {
        if (isset($grav['request'])
            && $grav['request'] instanceof \Psr\Http\Message\ServerRequestInterface) {
            return self::fromPsr($grav['request']);
        }

        return self::fromGlobals();
    }

    // ------------------------------------------------------------------
    // API (inspirée PSR-7)
    // ------------------------------------------------------------------

    /**
     * Corps brut de la requête, tel que reçu (octets exacts).
     *
     * Nécessaire aux webhooks, dont la signature se calcule sur le corps brut
     * et non sur les champs décodés.
     *
     * @return string
     */
    public function getRawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string) file_get_contents('php://input');
        }

        return $this->rawBody;
    }

    /**
     * Retourne la méthode HTTP (MAJUSCULES).
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Indique si la méthode courante correspond (comparaison insensible à la casse).
     *
     * @param string $method Méthode à tester.
     *
     * @return bool
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Indique si la requête est une requête POST.
     *
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * Indique si la requête est une requête GET.
     *
     * @return bool
     */
    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    /**
     * Retourne les paramètres de requête (GET).
     *
     * @return array
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Retourne le corps analysé (POST).
     *
     * @return array
     */
    public function getParsedBody(): array
    {
        return $this->parsedBody;
    }

    /**
     * Retourne les paramètres serveur.
     *
     * @return array
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * Retourne un paramètre serveur donné (ou une valeur par défaut).
     *
     * @param string $key     Clé recherchée.
     * @param mixed  $default Valeur par défaut.
     *
     * @return mixed
     */
    public function getServerParam(string $key, $default = null)
    {
        return $this->serverParams[$key] ?? $default;
    }

    /**
     * Retourne les cookies.
     *
     * @return array
     */
    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    /**
     * Retourne les fichiers téléversés.
     *
     * @return array
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    // ------------------------------------------------------------------
    // Accès filtré (compat avec l'existant)
    // ------------------------------------------------------------------

    /**
     * Lit et filtre une valeur des paramètres GET.
     *
     * @param string $key     Clé recherchée.
     * @param int    $filter  Filtre PHP (FILTER_*).
     * @param mixed  $default Valeur par défaut si absente ou filtre en échec.
     *
     * @return mixed
     */
    public function query(string $key, int $filter = FILTER_DEFAULT, $default = null)
    {
        return $this->filterFrom($this->queryParams, $key, $filter, $default);
    }

    /**
     * Lit et filtre une valeur des paramètres POST.
     *
     * @param string $key     Clé recherchée.
     * @param int    $filter  Filtre PHP (FILTER_*).
     * @param mixed  $default Valeur par défaut si absente ou filtre en échec.
     *
     * @return mixed
     */
    public function input(string $key, int $filter = FILTER_DEFAULT, $default = null)
    {
        return $this->filterFrom($this->parsedBody, $key, $filter, $default);
    }

    /**
     * Applique un filtre à une valeur issue d'un tableau interne.
     *
     * On utilise filter_var (et non filter_input) afin de rester testable et
     * cohérent avec les fabriques fromPsr()/fromGlobals().
     *
     * @param array  $source  Tableau source ($queryParams ou $parsedBody).
     * @param string $key     Clé recherchée.
     * @param int    $filter  Filtre PHP (FILTER_*).
     * @param mixed  $default Valeur par défaut.
     *
     * @return mixed
     */
    private function filterFrom(array $source, string $key, int $filter, $default)
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }

        $value = $source[$key];

        if ($filter === FILTER_DEFAULT) {
            return $value;
        }

        // filter_var attend un scalaire ; sur un tableau on retourne le défaut.
        if (is_array($value)) {
            return $default;
        }

        $filtered = filter_var($value, $filter);

        return ($filtered === false) ? $default : $filtered;
    }
}
