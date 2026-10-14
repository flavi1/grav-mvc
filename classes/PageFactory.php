<?php

namespace Grav\Plugin\Mvc;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Fabrique de pages virtuelles Grav à partir d'une Response.
 *
 * Reproduit de façon générique le mécanisme de page virtuelle codé en dur dans
 * `SimpleShopPlugin::onPageInitialized()` : on crée une `Page` non physique,
 * on lui affecte un header/route/template, puis on la substitue à la page
 * courante.
 *
 * Cette classe ne gère PAS :
 *  - la redirection (traitée en amont par mvc.php) ;
 *  - la sortie brute sans layout (émise directement par mvc.php via
 *    Response::isRawOutput()).
 * Elle suppose donc que la Response est destinée à être enveloppée par le
 * layout du thème (avec ou sans template applicatif).
 */
class PageFactory
{
    /**
     * Conteneur Grav.
     *
     * @var Grav
     */
    private Grav $grav;

    /**
     * Répertoire de base servant de "path" à la page virtuelle.
     *
     * @var string
     */
    private string $basePath;

    /**
     * @param Grav        $grav     Conteneur Grav.
     * @param string|null $basePath Répertoire de base (défaut : le plugin mvc).
     */
    public function __construct(Grav $grav, ?string $basePath = null)
    {
        $this->grav     = $grav;
        $this->basePath = $basePath ?? \dirname(__DIR__);
    }

    /**
     * Crée une page virtuelle Grav à partir d'une Response et l'injecte comme
     * page courante.
     *
     * @param Response $response Réponse à transcrire (non redirection, non
     *                           sortie brute).
     * @param string   $route    Route courante.
     *
     * @return Page La page virtuelle créée.
     */
	public function createFromResponse(Response $response, string$route): Page
	{
		$templateName =$response->getTemplate();
		$hasTemplate  =$response->hasTemplate();

		$title =$hasTemplate
			? 'MVC - ' . $templateName
			: 'MVC - ' . $route;

		$header = [
			'title'        => $title,
			'routable'     => true,
			'visible'      => false,
			'published'    => true,
			'cache_enable' => false
		];

		if ($hasTemplate) {
			$header['template'] =$templateName;
		}

		$page = new Page();
		$page->header($header);
		// Dossier « physique » de la page virtuelle : le dossier du plugin (existant, lisible). Un chemin inexistant
		// (ex. /dev/null) ferait échouer les appels de Grav qui listent les médias ou testent le dossier de la page.
		$page->path($this->basePath);
		$page->filePath($this->basePath . '/mvc-virtual-route.md');
		$page->id(md5($route));
		$page->name('virtual.md');
		$page->route($route);
		$page->rawRoute($route);

		if ($hasTemplate) {
			$page->template($templateName);
		} else {
			$page->content($response->getBody());
		}

		// L'enregistrement auprès de Grav (`$pages->addPage()`, qui évite la 404) est fait par mvc.php, une seule
		// fois, pour la route effectivement servie. Il ne va pas dans RouteManager::add() : celui-ci déclare toutes
		// les routes au démarrage, alors qu'une page virtuelle n'existe que pour la requête en cours.
		return $page;
	}
}
