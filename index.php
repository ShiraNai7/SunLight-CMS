<?php

use Sunlight\Core;
use Sunlight\Extend;
use Sunlight\GenericTemplates;
use Sunlight\Plugin\TemplatePlugin;
use Sunlight\Plugin\TemplateService;
use Sunlight\Router;
use Sunlight\Settings;
use Sunlight\Template;
use Sunlight\User;
use Sunlight\Util\Html;
use Sunlight\Util\Request;
use Sunlight\Util\Response;
use Sunlight\WebState;
use Sunlight\Xsrf;

require './system/bootstrap.php';
Core::init('./', [
    'env' => Core::ENV_WEB,
]);

/* ----  priprava  ---- */

// motiv
/** @var TemplatePlugin $_template */
$_template = null;
/** @var string $_template_layout */
$_template_layout = null;

// nacist vychozi motiv
if (!Template::change(TemplateService::composeUid(Settings::get('default_template'), TemplatePlugin::DEFAULT_LAYOUT))) {
    Settings::update('default_template', 'default');

    Core::fail(
        'Motiv "%s" nebyl nalezen.',
        'Template "%s" was not found.',
        [Settings::get('default_template')]
    );
}

// aktualni URL
$_url = Core::getCurrentUrl();

// presmerovat /index.php na /
if (substr($_url->getPath(), strlen(Core::getBaseUrl()->getPath())) === '/index.php' && !$_url->hasQuery()) {
    Response::redirect(Core::getBaseUrl()->build());
}

// init web state
$_index = new WebState();


/* ---- priprava obsahu ---- */

Extend::call('index.init', ['index' => $_index]);

$output = &$_index->output;

if (empty($_POST) || Xsrf::check()) {
    // zjisteni typu
    if (isset($_GET['m'])) {

        // modul
        $_index->slug = Request::get('m');
        $_index->isRewritten = !$_url->has('m');
        $_index->type = WebState::MODULE;

        Extend::call('mod.init');

        require SL_ROOT . 'system/action/module.php';

    } elseif (!User::isLoggedIn() && Settings::get('notpublicsite')) {

        // neverejne stranky
        $_index->isRewritten = Settings::get('pretty_urls');
        $_index->type = WebState::UNAUTHORIZED;

    } else do {

        // stranka / plugin
        if (Settings::get('pretty_urls') && isset($_GET['_rwp'])) {
            // hezka adresa
            $_index->slug = Request::get('_rwp');
            $_index->isRewritten = true;
        } elseif (isset($_GET['p'])) {
            // parametr
            $_index->slug = Request::get('p');
        }

        if ($_index->slug !== null) {
            $segments = explode('/', $_index->slug);
        } else {
            $segments = [];
        }

        if (!empty($segments) && $segments[count($segments) - 1] === '') {
            // presmerovat identifikator/ na identifikator
            $_url->setPath(rtrim($_url->getPath(), '/'));
            $_index->redirect($_url->build());
            break;
        }

        // extend
        Extend::call('index.plugin', [
            'index' => $_index,
            'segments' => $segments,
        ]);

        Extend::call('page.init');

        if ($_index->type === WebState::PLUGIN) {
            break;
        }

        // vykreslit stranku
        $_index->type = WebState::PAGE;
        require SL_ROOT . 'system/action/page.php';

    } while (false);
} else {
    // spatny XSRF token
    require SL_ROOT . 'system/action/xsrf_error.php';
}

/* ----  vystup  ---- */

Extend::call('index.prepare', ['index' => $_index]);

// zpracovani stavu
switch ($_index->type) {
    case WebState::REDIR:
        // presmerovani
        $_index->templateEnabled = false;
        Response::redirect($_index->redirectTo, $_index->redirectToPermanent);
        break;

    case WebState::NOT_FOUND:
        // stranka nenelezena
        require SL_ROOT . 'system/action/not_found.php';
        break;

    case WebState::UNAUTHORIZED:
        // pristup odepren
        require SL_ROOT . 'system/action/login_required.php';
        break;
}

Extend::call('index.ready', ['index' => $_index]);

// vlozeni motivu
if ($_index->templateEnabled) {
    // nacist prvky motivu
    $_template->begin($_template_layout);
    $_template_boxes = $_template->getBoxes($_template_layout);
    $_template_path = $_template->getTemplate($_template_layout);

    Extend::call('index.template', [
        'path' => &$_template_path,
        'boxes' => &$_template_boxes,
    ]);

    // hlavicka
    echo GenericTemplates::renderHead();
    Template::head();

    ?>
</head>
<body<?php if ($_index->bodyClasses): ?> class="<?= implode(' ', Html::escapeArrayItems($_index->bodyClasses)) ?>"<?php endif ?><?= Extend::buffer('tpl.body_tag') ?>>

<?php require $_template_path ?>
<?= Extend::buffer('tpl.end') ?>

</body>
</html>
<?php
}

Extend::call('index.finish', ['index' => $_index]);
