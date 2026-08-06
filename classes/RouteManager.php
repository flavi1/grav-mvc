<?php

namespace Grav\Plugin\Mvc;

/**
 * Gestionnaire de routes.
 *
 * Stocke une table de correspondance entre une route (chemin d'URL) et un
 * couple [classe, méthode] de contrôleur à invoquer lorsque cette route est
 * résolue. Cette classe est un POPO (Plain Old PHP Object) : elle ne dépend
 * d'aucune API Grav, ce qui la rend facilement testable de manière isolée.
 */
class RouteManager
{
    /**
     * Table des routes enregistrées.
     *
     * Format : ['/route' => ['class' => FQCN, 'method' => 'nomMethode'], ...]
     *
     * @var array<string, array{class: string, method: string}>
     */
    private array $routes = [];

    /**
     * Ajoute une route unique à la table.
     *
     * @param string $route                 Chemin de la route (ex. '/cart').
     * @param array  $controllerClassMethod  Couple [ClasseController::class, 'nomMethode'].
     *
     * @throws \InvalidArgumentException Si la classe n'existe pas ou si la
     *                                    méthode n'existe pas dans cette classe.
     *
     * @return self Permet le chaînage des appels.
     */
    public function add(string $route, array $controllerClassMethod): self
    {
        [$class, $method] = $controllerClassMethod;

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(
                "RouteManager : la classe '{$class}' n'existe pas pour la route '{$route}'."
            );
        }

        if (!method_exists($class, $method)) {
            throw new \InvalidArgumentException(
                "RouteManager : la méthode '{$method}' n'existe pas dans la classe '{$class}' pour la route '{$route}'."
            );
        }

        $this->routes[$route] = [
            'class'  => $class,
            'method' => $method
        ];

        return $this;
    }

    /**
     * Ajoute plusieurs routes en une seule fois.
     *
     * Format attendu : ['/cart' => [CartController::class, 'step1Cart'], ...].
     *
     * @param array<string, array> $map Table des routes à ajouter.
     *
     * @throws \InvalidArgumentException Si une classe ou une méthode n'existe pas.
     *
     * @return self Permet le chaînage des appels.
     */
    public function addMany(array $map): self
    {
        foreach ($map as $route => $controllerClassMethod) {
            $this->add($route, $controllerClassMethod);
        }

        return $this;
    }

    /**
     * Résout une route vers son couple [classe, méthode].
     *
     * @param string $route Chemin de la route à résoudre.
     *
     * @return array{class: string, method: string}|null Le couple résolu,
     *                                                     ou null si aucune
     *                                                     correspondance.
     */
    public function match(string $route): ?array
    {
        return $this->routes[$route] ?? null;
    }

    /**
     * Retourne la table complète des routes enregistrées.
     *
     * Utile pour le débogage ou les tests unitaires.
     *
     * @return array<string, array{class: string, method: string}>
     */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * Retourne uniquement les chemins (clés) de la table des routes.
     *
     * @return string[]
     */
    public function routes(): array
    {
        return array_keys($this->routes);
    }
}
