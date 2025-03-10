<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\Admin;

use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Config;
use Pimcore\Controller\Config\ControllerDataProvider;
use Pimcore\Db;
use Pimcore\File;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Tool;
use Pimcore\Tool\Storage;
use Pimcore\Translation\Translator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/misc")
 *
 * @internal
 */
class MiscController extends AdminController
{
    /**
     * @Route("/get-available-controller-references", name="pimcore_admin_misc_getavailablecontroller_references", methods={"GET"})
     *
     * @param Request $request
     * @param ControllerDataProvider $provider
     *
     * @return JsonResponse
     */
    public function getAvailableControllerReferencesAction(Request $request, ControllerDataProvider $provider)
    {
        $controllerReferences = $provider->getControllerReferences();

        $result = array_map(function ($controller) {
            return [
                'name' => $controller,
            ];
        }, $controllerReferences);

        return $this->adminJson([
            'data' => $result,
        ]);
    }

    /**
     * @Route("/get-available-templates", name="pimcore_admin_misc_getavailabletemplates", methods={"GET"})
     *
     * @param ControllerDataProvider $provider
     *
     * @return JsonResponse
     */
    public function getAvailableTemplatesAction(ControllerDataProvider $provider)
    {
        $templates = $provider->getTemplates();

        sort($templates, SORT_NATURAL | SORT_FLAG_CASE);

        $result = array_map(static function ($template) {
            return [
                'path' => $template,
            ];
        }, $templates);

        return $this->adminJson([
            'data' => $result,
        ]);
    }

    /**
     * @Route("/json-translations-system", name="pimcore_admin_misc_jsontranslationssystem", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function jsonTranslationsSystemAction(Request $request, TranslatorInterface $translator)
    {
        $language = $request->get('language');

        /** @var Translator $translator */
        $translator->lazyInitialize('admin', $language);

        $translations = $translator->getCatalogue($language)->all('admin');
        if ($language != 'en') {
            // add en as a fallback
            $translator->lazyInitialize('admin', 'en');
            foreach ($translator->getCatalogue('en')->all('admin') as $key => $value) {
                if (!isset($translations[$key]) || empty($translations[$key])) {
                    $translations[$key] = $value;
                }
            }
        }

        $response = new Response('pimcore.system_i18n = ' . $this->encodeJson($translations) . ';');
        $response->headers->set('Content-Type', 'text/javascript');

        return $response;
    }

    /**
     * @Route("/script-proxy", name="pimcore_admin_misc_scriptproxy", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function scriptProxyAction(Request $request)
    {
        if ($storageFile = $request->get('storageFile')) {
            $fileExtension = \Pimcore\File::getFileExtension($storageFile);
            $storage = Storage::get('admin');
            $scriptsContent = $storage->read($storageFile);
        } else {
            trigger_deprecation('pimcore/pimcore', '10.1', 'Calling /admin/misc/script-proxy without the parameter storageFile is deprecated and will not work in Pimcore 11.');
            $allowedFileTypes = ['js', 'css'];
            $scripts = explode(',', $request->get('scripts'));

            if ($request->get('scriptPath')) {
                $scriptPath = PIMCORE_WEB_ROOT . $request->get('scriptPath');
            } else {
                $scriptPath = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/';
            }

            $scriptsContent = '';
            foreach ($scripts as $script) {
                $filePath = $scriptPath . $script;
                if (is_file($filePath) && is_readable($filePath) && in_array(\Pimcore\File::getFileExtension($script), $allowedFileTypes)) {
                    $scriptsContent .= file_get_contents($filePath);
                }
            }

            $fileExtension = \Pimcore\File::getFileExtension($scripts[0]);
        }

        if (!empty($scriptsContent)) {
            $contentType = 'text/javascript';
            if ($fileExtension == 'css') {
                $contentType = 'text/css';
            }

            $lifetime = 86400;

            $response = new Response($scriptsContent);
            $response->headers->set('Cache-Control', 'max-age=' . $lifetime);
            $response->headers->set('Pragma', '');
            $response->headers->set('Content-Type', $contentType);
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $lifetime) . ' GMT');

            return $response;
        } else {
            throw $this->createNotFoundException('Scripts not found');
        }
    }

    /**
     * @Route("/admin-css", name="pimcore_admin_misc_admincss", methods={"GET"})
     *
     * @param Request $request
     * @param Config $config
     *
     * @return Response
     */
    public function adminCssAction(Request $request, Config $config)
    {
        // customviews config
        $cvData = \Pimcore\CustomView\Config::get();

        // languages
        $languages = \Pimcore\Tool::getValidLanguages();
        $adminLanguages = \Pimcore\Tool\Admin::getLanguages();
        $languages = array_unique(array_merge($languages, $adminLanguages));

        $response = $this->render('@PimcoreAdmin/Admin/Misc/admin-css.html.twig', [
            'customviews' => $cvData,
            'config' => $config,
            'languages' => $languages,
        ]);
        $response->headers->set('Content-Type', 'text/css; charset=UTF-8');

        return $response;
    }

    /**
     * @Route("/ping", name="pimcore_admin_misc_ping", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function pingAction(Request $request)
    {
        $response = [
            'success' => true,
        ];

        return $this->adminJson($response);
    }

    /**
     * @Route("/available-languages", name="pimcore_admin_misc_availablelanguages", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function availableLanguagesAction(Request $request)
    {
        $locales = Tool::getSupportedLocales();
        $response = new Response('pimcore.available_languages = ' . $this->encodeJson($locales) . ';');
        $response->headers->set('Content-Type', 'text/javascript');

        return $response;
    }

    /**
     * @Route("/get-valid-filename", name="pimcore_admin_misc_getvalidfilename", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getValidFilenameAction(Request $request)
    {
        return $this->adminJson([
            'filename' => \Pimcore\Model\Element\Service::getValidKey($request->get('value'), $request->get('type')),
        ]);
    }

    // FILEEXPLORER

    /**
     * @Route("/fileexplorer-tree", name="pimcore_admin_misc_fileexplorertree", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fileexplorerTreeAction(Request $request)
    {
        $this->checkPermission('fileexplorer');
        $referencePath = $this->getFileexplorerPath($request, 'node');

        $items = scandir($referencePath);
        $contents = [];

        foreach ($items as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            $file = $referencePath . '/' . $item;
            $file = str_replace('//', '/', $file);

            if (is_dir($file) || is_file($file)) {
                $itemConfig = [
                    'id' => '/fileexplorer' . str_replace(PIMCORE_PROJECT_ROOT, '', $file),
                    'text' => $item,
                    'leaf' => true,
                    'writeable' => is_writable($file),
                ];

                if (is_dir($file)) {
                    $itemConfig['leaf'] = false;
                    $itemConfig['type'] = 'folder';
                    if (is_dir_empty($file)) {
                        $itemConfig['loaded'] = true;
                    }
                    $itemConfig['expandable'] = true;
                } elseif (is_file($file)) {
                    $itemConfig['type'] = 'file';
                }

                $contents[] = $itemConfig;
            }
        }

        return $this->adminJson($contents);
    }

    /**
     * @Route("/fileexplorer-content", name="pimcore_admin_misc_fileexplorercontent", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fileexplorerContentAction(Request $request)
    {
        $this->checkPermission('fileexplorer');

        $success = false;
        $writeable = false;
        $file = $this->getFileexplorerPath($request, 'path');
        $content = null;
        if (is_file($file)) {
            if (is_readable($file)) {
                $content = file_get_contents($file);
                $success = true;
                $writeable = is_writable($file);
            }
        }

        return $this->adminJson([
            'success' => $success,
            'content' => $content,
            'writeable' => $writeable,
            'filename' => basename($file),
            'path' => preg_replace('@^' . preg_quote(PIMCORE_PROJECT_ROOT, '@') . '@', '', $file),
        ]);
    }

    /**
     * @Route("/fileexplorer-content-save", name="pimcore_admin_misc_fileexplorercontentsave", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fileexplorerContentSaveAction(Request $request)
    {
        $this->checkPermission('fileexplorer');

        $success = false;

        if ($request->get('content') && $request->get('path')) {
            $file = $this->getFileexplorerPath($request, 'path');
            if (is_file($file) && is_writable($file)) {
                File::put($file, $request->get('content'));

                $success = true;
            }
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/fileexplorer-add", name="pimcore_admin_misc_fileexploreradd", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function fileexplorerAddAction(Request $request)
    {
        $this->checkPermission('fileexplorer');

        $success = false;

        if ($request->get('filename') && $request->get('path')) {
            $path = $this->getFileexplorerPath($request, 'path');
            $file = $path . '/' . $request->get('filename');

            $file = resolvePath($file);
            if (strpos($file, PIMCORE_PROJECT_ROOT) !== 0) {
                throw new \Exception('not allowed');
            }

            if (is_writable(dirname($file))) {
                File::put($file, '');

                $success = true;
            }
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/fileexplorer-add-folder", name="pimcore_admin_misc_fileexploreraddfolder", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function fileexplorerAddFolderAction(Request $request)
    {
        $this->checkPermission('fileexplorer');

        $success = false;

        if ($request->get('filename') && $request->get('path')) {
            $path = $this->getFileexplorerPath($request, 'path');
            $file = $path . '/' . $request->get('filename');

            $file = resolvePath($file);
            if (strpos($file, PIMCORE_PROJECT_ROOT) !== 0) {
                throw new \Exception('not allowed');
            }

            if (is_writable(dirname($file))) {
                File::mkdir($file);

                $success = true;
            }
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/fileexplorer-delete", name="pimcore_admin_misc_fileexplorerdelete", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fileexplorerDeleteAction(Request $request)
    {
        $this->checkPermission('fileexplorer');
        $success = false;

        if ($request->get('path')) {
            $file = $this->getFileexplorerPath($request, 'path');
            if (is_writable($file)) {
                unlink($file);
                $success = true;
            }
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/fileexplorer-rename", name="pimcore_admin_misc_fileexplorerrename", methods={"PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fileexplorerRenameAction(Request $request)
    {
        $this->checkPermission('fileexplorer');
        $success = false;

        if ($request->get('path') && $request->get('newPath')) {
            $file = $this->getFileexplorerPath($request, 'path');
            $newFile = $this->getFileexplorerPath($request, 'newPath');

            $success = rename($file, $newFile);
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @param Request $request
     * @param string $paramName
     *
     * @return string
     *
     * @throws \Exception
     */
    private function getFileexplorerPath(Request $request, $paramName = 'node')
    {
        $path = preg_replace("/^\/fileexplorer/", '', $request->get($paramName));
        $path = resolvePath(PIMCORE_PROJECT_ROOT . $path);

        if (strpos($path, PIMCORE_PROJECT_ROOT) !== 0) {
            throw new \Exception('operation permitted, permission denied');
        }

        return $path;
    }

    /**
     * @Route("/maintenance", name="pimcore_admin_misc_maintenance", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function maintenanceAction(Request $request)
    {
        $this->checkPermission('maintenance_mode');

        if ($request->get('activate')) {
            Tool\Admin::activateMaintenanceMode(Tool\Session::getSessionId());
        }

        if ($request->get('deactivate')) {
            Tool\Admin::deactivateMaintenanceMode();
        }

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/http-error-log", name="pimcore_admin_misc_httperrorlog", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function httpErrorLogAction(Request $request)
    {
        $this->checkPermission('http_errors');

        $db = Db::get();

        $limit = (int)$request->get('limit');
        $offset = (int)$request->get('start');
        $sortInfo = ($request->get('sort') ? json_decode($request->get('sort'), true)[0] : []);
        $sort = $sortInfo['property'] ?? null;
        $dir = $sortInfo['direction'] ?? null;
        $filter = $request->get('filter');
        if (!$limit) {
            $limit = 20;
        }
        if (!$offset) {
            $offset = 0;
        }
        if (!$sort || !in_array($sort, ['code', 'uri', 'date', 'count'])) {
            $sort = 'count';
        }
        if (!$dir || !in_array($dir, ['DESC', 'ASC'])) {
            $dir = 'DESC';
        }

        $condition = '';
        if ($filter) {
            $filter = $db->quote('%' . $filter . '%');

            $conditionParts = [];
            foreach (['uri', 'code', 'parametersGet', 'parametersPost', 'serverVars', 'cookies'] as $field) {
                $conditionParts[] = $field . ' LIKE ' . $filter;
            }
            $condition = ' WHERE ' . implode(' OR ', $conditionParts);
        }

        $logs = $db->fetchAll('SELECT code,uri,`count`,date FROM http_error_log ' . $condition . ' ORDER BY ' . $sort . ' ' . $dir . ' LIMIT ' . $offset . ',' . $limit);
        $total = $db->fetchOne('SELECT count(*) FROM http_error_log ' . $condition);

        return $this->adminJson([
            'items' => $logs,
            'total' => $total,
            'success' => true,
        ]);
    }

    /**
     * @Route("/http-error-log-flush", name="pimcore_admin_misc_httperrorlogflush", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function httpErrorLogFlushAction(Request $request)
    {
        $this->checkPermission('http_errors');

        $db = Db::get();
        $db->query('TRUNCATE TABLE http_error_log');

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/http-error-log-detail", name="pimcore_admin_misc_httperrorlogdetail", methods={"GET"})
     *
     * @param Request $request
     * @param Profiler|null $profiler
     *
     * @return Response
     */
    public function httpErrorLogDetailAction(Request $request, ?Profiler $profiler)
    {
        $this->checkPermission('http_errors');

        if ($profiler) {
            $profiler->disable();
        }

        $db = Db::get();
        $data = $db->fetchAssociative('SELECT * FROM http_error_log WHERE uri = ?', [$request->get('uri')]);

        foreach ($data as $key => &$value) {
            if (in_array($key, ['parametersGet', 'parametersPost', 'serverVars', 'cookies'])) {
                $value = unserialize($value);
            }
        }

        $response = $this->render('@PimcoreAdmin/Admin/Misc/http-error-log-detail.html.twig', ['data' => $data]);

        return $response;
    }

    /**
     * @Route("/country-list", name="pimcore_admin_misc_countrylist", methods={"GET"})
     *
     * @param LocaleServiceInterface $localeService
     *
     * @return JsonResponse
     */
    public function countryListAction(LocaleServiceInterface $localeService)
    {
        $countries = $localeService->getDisplayRegions();
        asort($countries);
        $options = [];

        foreach ($countries as $short => $translation) {
            if (strlen($short) == 2) {
                $options[] = [
                    'name' => $translation,
                    'code' => $short,
                ];
            }
        }

        return $this->adminJson(['data' => $options]);
    }

    /**
     * @Route("/language-list", name="pimcore_admin_misc_languagelist", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function languageListAction(Request $request)
    {
        $locales = Tool::getSupportedLocales();
        $options = [];

        foreach ($locales as $short => $translation) {
            $options[] = [
                'name' => $translation,
                'code' => $short,
            ];
        }

        return $this->adminJson(['data' => $options]);
    }

    /**
     * @Route("/phpinfo", name="pimcore_admin_misc_phpinfo", methods={"GET"})
     *
     * @param Request $request
     * @param Profiler|null $profiler
     *
     * @throws \Exception
     *
     * @return Response
     */
    public function phpinfoAction(Request $request, ?Profiler $profiler)
    {
        if ($profiler) {
            $profiler->disable();
        }

        if (!$this->getAdminUser()->isAdmin()) {
            throw new \Exception('Permission denied');
        }

        ob_start();
        phpinfo();
        $content = ob_get_clean();

        return new Response($content);
    }

    /**
     * @Route("/get-language-flag", name="pimcore_admin_misc_getlanguageflag", methods={"GET"})
     *
     * @param Request $request
     *
     * @return BinaryFileResponse
     */
    public function getLanguageFlagAction(Request $request)
    {
        $iconPath = Tool::getLanguageFlagFile($request->get('language'));
        $response = new BinaryFileResponse($iconPath);
        $response->headers->set('Content-Type', 'image/svg+xml');

        return $response;
    }

    /**
     * @Route("/icon-list", name="pimcore_admin_misc_iconlist", methods={"GET"})
     *
     * @param Request $request
     * @param Profiler|null $profiler
     *
     * @return Response
     */
    public function iconListAction(Request $request, ?Profiler $profiler)
    {
        if ($profiler) {
            $profiler->disable();
        }

        $publicDir = PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin';
        $iconDir = $publicDir . '/img';
        $colorIcons = rscandir($iconDir . '/flat-color-icons/');
        $whiteIcons = rscandir($iconDir . '/flat-white-icons/');
        $twemoji = rscandir($iconDir . '/twemoji/');

        //flag icons for locales
        $locales = Tool::getSupportedLocales();
        $languageOptions = [];
        foreach ($locales as $short => $translation) {
            if (!empty($short)) {
                $languageOptions[] = [
                    'language' => $short,
                    'display' => $translation . " ($short)",
                    'flag' => \Pimcore\Tool::getLanguageFlagFile($short, true),
                ];
            }
        }

        $iconsCss = file_get_contents($publicDir . '/css/icons.css');

        return $this->render('@PimcoreAdmin/Admin/Misc/iconList.html.twig', [
            'colorIcons' => $colorIcons,
            'whiteIcons' => $whiteIcons,
            'twemoji' => $twemoji,
            'languageOptions' => $languageOptions,
            'iconsCss' => $iconsCss,
        ]);
    }

    /**
     * @Route("/test", name="pimcore_admin_misc_test")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function testAction(Request $request)
    {
        return new Response('done');
    }
}
