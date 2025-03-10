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

namespace Pimcore\Model\Document;

use Pimcore\Config;
use Pimcore\Document\Renderer\DocumentRenderer;
use Pimcore\Document\Renderer\DocumentRendererInterface;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\File;
use Pimcore\Image\Chromium;
use Pimcore\Image\HtmlToImage;
use Pimcore\Model;
use Pimcore\Model\Document;
use Pimcore\Model\Document\Editable\IdRewriterInterface;
use Pimcore\Model\Document\Editable\LazyLoadingInterface;
use Pimcore\Model\Element;
use Pimcore\Tool;
use Pimcore\Tool\Serialize;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \Pimcore\Model\Document\Service\Dao getDao()
 * @method int[] getTranslations(Document $document, string $task = 'open')
 * @method addTranslation(Document $document, Document $translation, $language = null)
 * @method removeTranslation(Document $document)
 * @method int getTranslationSourceId(Document $document)
 * @method removeTranslationLink(Document $document, Document $targetDocument)
 */
class Service extends Model\Element\Service
{
    /**
     * @var Model\User|null
     */
    protected $_user;

    /**
     * @var array
     */
    protected $_copyRecursiveIds;

    /**
     * @var Document[]
     */
    protected $nearestPathCache;

    /**
     * @param Model\User $user
     */
    public function __construct($user = null)
    {
        $this->_user = $user;
    }

    /**
     * Renders a document outside of a view
     *
     * Parameter order was kept for BC (useLayout before query and options).
     *
     * @static
     *
     * @param Document\PageSnippet $document
     * @param array $attributes
     * @param bool $useLayout
     * @param array $query
     * @param array $options
     *
     * @return string
     */
    public static function render(Document\PageSnippet $document, array $attributes = [], $useLayout = false, array $query = [], array $options = []): string
    {
        $container = \Pimcore::getContainer();

        /** @var DocumentRendererInterface $renderer */
        $renderer = $container->get(DocumentRenderer::class);

        // keep useLayout compatibility
        $attributes['_useLayout'] = $useLayout;
        $content = $renderer->render($document, $attributes, $query, $options);

        return $content;
    }

    /**
     * Save document and all child documents
     *
     * @param Document $document
     * @param int $collectGarbageAfterIteration
     * @param int $saved
     *
     * @throws \Exception
     */
    private static function saveRecursive($document, $collectGarbageAfterIteration = 25, &$saved = 0)
    {
        if ($document instanceof Document) {
            $document->save();
            $saved++;
            if ($saved % $collectGarbageAfterIteration === 0) {
                \Pimcore::collectGarbage();
            }
        }

        foreach ($document->getChildren() as $child) {
            if (!$child->hasChildren()) {
                $child->save();
                $saved++;
                if ($saved % $collectGarbageAfterIteration === 0) {
                    \Pimcore::collectGarbage();
                }
            }
            if ($child->hasChildren()) {
                self::saveRecursive($child, $collectGarbageAfterIteration, $saved);
            }
        }
    }

    /**
     * @param  Document $target
     * @param  Document $source
     *
     * @return Document|null copied document
     *
     * @throws \Exception
     */
    public function copyRecursive($target, $source)
    {

        // avoid recursion
        if (!$this->_copyRecursiveIds) {
            $this->_copyRecursiveIds = [];
        }
        if (in_array($source->getId(), $this->_copyRecursiveIds)) {
            return null;
        }

        if ($source instanceof Document\PageSnippet) {
            $source->getEditables();
        }

        $source->getProperties();

        // triggers actions before document cloning
        $event = new DocumentEvent($source, [
            'target_element' => $target,
        ]);
        \Pimcore::getEventDispatcher()->dispatch($event, DocumentEvents::PRE_COPY);
        $target = $event->getArgument('target_element');

        /** @var Document $new */
        $new = Element\Service::cloneMe($source);
        $new->setId(null);
        $new->setChildren(null);
        $new->setKey(Element\Service::getSafeCopyName($new->getKey(), $target));
        $new->setParentId($target->getId());
        $new->setUserOwner($this->_user ? $this->_user->getId() : 0);
        $new->setUserModification($this->_user ? $this->_user->getId() : 0);
        $new->setDao(null);
        $new->setLocked(null);
        $new->setCreationDate(time());
        if ($new instanceof Page) {
            $new->setPrettyUrl(null);
        }

        $new->save();

        // add to store
        $this->_copyRecursiveIds[] = $new->getId();

        foreach ($source->getChildren(true) as $child) {
            $this->copyRecursive($new, $child);
        }

        $this->updateChildren($target, $new);

        // triggers actions after the complete document cloning
        $event = new DocumentEvent($new, [
            'base_element' => $source, // the element used to make a copy
        ]);
        \Pimcore::getEventDispatcher()->dispatch($event, DocumentEvents::POST_COPY);

        return $new;
    }

    /**
     * @param Document $target
     * @param Document $source
     * @param bool $enableInheritance
     * @param bool $resetIndex
     *
     * @return Document
     *
     * @throws \Exception
     */
    public function copyAsChild($target, $source, $enableInheritance = false, $resetIndex = false, $language = false)
    {
        if ($source instanceof Document\PageSnippet) {
            $source->getEditables();
        }

        $source->getProperties();

        // triggers actions before document cloning
        $event = new DocumentEvent($source, [
            'target_element' => $target,
        ]);
        \Pimcore::getEventDispatcher()->dispatch($event, DocumentEvents::PRE_COPY);
        $target = $event->getArgument('target_element');

        /**
         * @var Document $new
         */
        $new = Element\Service::cloneMe($source);
        $new->setId(null);
        $new->setChildren(null);
        $new->setKey(Element\Service::getSafeCopyName($new->getKey(), $target));
        $new->setParentId($target->getId());
        $new->setUserOwner($this->_user ? $this->_user->getId() : 0);
        $new->setUserModification($this->_user ? $this->_user->getId() : 0);
        $new->setDao(null);
        $new->setLocked(null);
        $new->setCreationDate(time());

        if ($resetIndex) {
            // this needs to be after $new->setParentId($target->getId()); -> dependency!
            $new->setIndex($new->getDao()->getNextIndex());
        }

        if ($new instanceof Page) {
            $new->setPrettyUrl(null);
        }

        if ($enableInheritance && ($new instanceof Document\PageSnippet) && $new->supportsContentMaster()) {
            $new->setEditables([]);
            $new->setContentMasterDocumentId($source->getId());
        }

        if ($language) {
            $new->setProperty('language', 'text', $language, false, true);
        }

        $new->save();

        $this->updateChildren($target, $new);

        //link translated document
        if ($language) {
            $this->addTranslation($source, $new, $language);
        }

        // triggers actions after the complete document cloning
        $event = new DocumentEvent($new, [
            'base_element' => $source, // the element used to make a copy
        ]);
        \Pimcore::getEventDispatcher()->dispatch($event, DocumentEvents::POST_COPY);

        return $new;
    }

    /**
     * @param Document $target
     * @param Document $source
     *
     * @return Document
     *
     * @throws \Exception
     */
    public function copyContents($target, $source)
    {
        // check if the type is the same
        if (get_class($source) != get_class($target)) {
            throw new \Exception('Source and target have to be the same type');
        }

        if ($source instanceof Document\PageSnippet) {
            /** @var PageSnippet $target */
            $target->setEditables($source->getEditables());

            $target->setTemplate($source->getTemplate());
            $target->setController($source->getController());

            if ($source instanceof Document\Page) {
                /** @var Page $target */
                $target->setTitle($source->getTitle());
                $target->setDescription($source->getDescription());
            }
        } elseif ($source instanceof Document\Link) {
            /** @var Link $target */
            $target->setInternalType($source->getInternalType());
            $target->setInternal($source->getInternal());
            $target->setDirect($source->getDirect());
            $target->setLinktype($source->getLinktype());
        }

        $target->setUserModification($this->_user ? $this->_user->getId() : 0);
        $target->setProperties(self::cloneProperties($source->getProperties()));
        $target->save();

        return $target;
    }

    /**
     * @param Document $document
     *
     * @return array
     *
     * @internal
     */
    public static function gridDocumentData($document)
    {
        $data = Element\Service::gridElementData($document);

        if ($document instanceof Document\Page) {
            $data['title'] = $document->getTitle();
            $data['description'] = $document->getDescription();
        } else {
            $data['title'] = '';
            $data['description'] = '';
            $data['name'] = '';
        }

        return $data;
    }

    /**
     * @internal
     *
     * @param Document $doc
     *
     * @return Document
     */
    public static function loadAllDocumentFields($doc)
    {
        $doc->getProperties();

        if ($doc instanceof Document\PageSnippet) {
            foreach ($doc->getEditables() as $name => $data) {
                //TODO Pimcore 11: remove method_exists BC layer
                if ($data instanceof LazyLoadingInterface || method_exists($data, 'load')) {
                    if (!$data instanceof LazyLoadingInterface) {
                        trigger_deprecation('pimcore/pimcore', '10.3',
                            sprintf('Usage of method_exists is deprecated since version 10.3 and will be removed in Pimcore 11.' .
                                'Implement the %s interface instead.', LazyLoadingInterface::class));
                    }
                    $data->load();
                }
            }
        }

        return $doc;
    }

    /**
     * @static
     *
     * @param string $path
     * @param string|null $type
     *
     * @return bool
     */
    public static function pathExists($path, $type = null)
    {
        if (!$path) {
            return false;
        }

        $path = Element\Service::correctPath($path);

        try {
            $document = new Document();
            // validate path
            if (self::isValidPath($path, 'document')) {
                $document->getDao()->getByPath($path);

                return true;
            }
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    public static function isValidType($type)
    {
        return in_array($type, Document::getTypes());
    }

    /**
     * Rewrites id from source to target, $rewriteConfig contains
     * array(
     *  "document" => array(
     *      SOURCE_ID => TARGET_ID,
     *      SOURCE_ID => TARGET_ID
     *  ),
     *  "object" => array(...),
     *  "asset" => array(...)
     * )
     *
     * @internal
     *
     * @param Document $document
     * @param array $rewriteConfig
     * @param array $params
     *
     * @return Document
     */
    public static function rewriteIds($document, $rewriteConfig, $params = [])
    {

        // rewriting elements only for snippets and pages
        if ($document instanceof Document\PageSnippet) {
            if (array_key_exists('enableInheritance', $params) && $params['enableInheritance']) {
                $editables = $document->getEditables();
                $changedEditables = [];
                $contentMaster = $document->getContentMasterDocument();
                if ($contentMaster instanceof Document\PageSnippet) {
                    $contentMasterEditables = $contentMaster->getEditables();
                    foreach ($contentMasterEditables as $contentMasterEditable) {
                        //TODO Pimcore 11: remove method_exists BC layer
                        if ($contentMasterEditable instanceof IdRewriterInterface || method_exists($contentMasterEditable, 'rewriteIds')) {
                            if (!$contentMasterEditable instanceof IdRewriterInterface) {
                                trigger_deprecation('pimcore/pimcore', '10.3',
                                    sprintf('Usage of method_exists is deprecated since version 10.3 and will be removed in Pimcore 11.' .
                                        'Implement the %s interface instead.', IdRewriterInterface::class));
                            }
                            $editable = clone $contentMasterEditable;
                            $editable->rewriteIds($rewriteConfig);

                            if (Serialize::serialize($editable) != Serialize::serialize($contentMasterEditable)) {
                                $changedEditables[] = $editable;
                            }
                        }
                    }
                }

                if (count($changedEditables) > 0) {
                    $editables = $changedEditables;
                }
            } else {
                $editables = $document->getEditables();
                foreach ($editables as &$editable) {
                    //TODO Pimcore 11: remove method_exists BC layer
                    if ($editable instanceof IdRewriterInterface || method_exists($editable, 'rewriteIds')) {
                        if (!$editable instanceof IdRewriterInterface) {
                            trigger_deprecation('pimcore/pimcore', '10.3',
                                sprintf('Usage of method_exists is deprecated since version 10.3 and will be removed in Pimcore 11.' .
                                    'Implement the %s interface instead.', IdRewriterInterface::class));
                        }
                        $editable->rewriteIds($rewriteConfig);
                    }
                }
            }

            $document->setEditables($editables);
        } elseif ($document instanceof Document\Hardlink) {
            if (array_key_exists('document', $rewriteConfig) && $document->getSourceId() && array_key_exists((int) $document->getSourceId(), $rewriteConfig['document'])) {
                $document->setSourceId($rewriteConfig['document'][(int) $document->getSourceId()]);
            }
        } elseif ($document instanceof Document\Link) {
            if (array_key_exists('document', $rewriteConfig) && $document->getLinktype() == 'internal' && $document->getInternalType() == 'document' && array_key_exists((int) $document->getInternal(), $rewriteConfig['document'])) {
                $document->setInternal($rewriteConfig['document'][(int) $document->getInternal()]);
            }
        }

        // rewriting properties
        $properties = $document->getProperties();
        foreach ($properties as &$property) {
            $property->rewriteIds($rewriteConfig);
        }
        $document->setProperties($properties);

        return $document;
    }

    /**
     * @internal
     *
     * @param string $url
     *
     * @return Document|null
     */
    public static function getByUrl($url)
    {
        $urlParts = parse_url($url);
        $document = null;

        if ($urlParts['path']) {
            $document = Document::getByPath($urlParts['path']);

            // search for a page in a site
            if (!$document) {
                $sitesList = new Model\Site\Listing();
                $sitesObjects = $sitesList->load();

                foreach ($sitesObjects as $site) {
                    if ($site->getRootDocument() && (in_array($urlParts['host'], $site->getDomains()) || $site->getMainDomain() == $urlParts['host'])) {
                        if ($document = Document::getByPath($site->getRootDocument() . $urlParts['path'])) {
                            break;
                        }
                    }
                }
            }
        }

        return $document;
    }

    /**
     * @param Document $item
     * @param int $nr
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function getUniqueKey($item, $nr = 0)
    {
        $list = new Listing();
        $list->setUnpublished(true);
        $key = Element\Service::getValidKey($item->getKey(), 'document');
        if (!$key) {
            throw new \Exception('No item key set.');
        }
        if ($nr) {
            $key = $key . '_' . $nr;
        }

        $parent = $item->getParent();
        if (!$parent) {
            throw new \Exception('You have to set a parent document to determine a unique Key');
        }

        if (!$item->getId()) {
            $list->setCondition('parentId = ? AND `key` = ? ', [$parent->getId(), $key]);
        } else {
            $list->setCondition('parentId = ? AND `key` = ? AND id != ? ', [$parent->getId(), $key, $item->getId()]);
        }
        $check = $list->loadIdList();
        if (!empty($check)) {
            $nr++;
            $key = self::getUniqueKey($item, $nr);
        }

        return $key;
    }

    /**
     * Get the nearest document by path. Used to match nearest document for a static route.
     *
     * @internal
     *
     * @param string|Request $path
     * @param bool $ignoreHardlinks
     * @param array $types
     *
     * @return Document|null
     */
    public function getNearestDocumentByPath($path, $ignoreHardlinks = false, $types = [])
    {
        if ($path instanceof Request) {
            $path = urldecode($path->getPathInfo());
        }

        $cacheKey = $ignoreHardlinks . implode('-', $types);
        $document = null;

        if (isset($this->nearestPathCache[$cacheKey])) {
            $document = $this->nearestPathCache[$cacheKey];
        } else {
            $paths = ['/'];
            $tmpPaths = [];

            $pathParts = explode('/', $path);
            foreach ($pathParts as $pathPart) {
                $tmpPaths[] = $pathPart;

                $t = implode('/', $tmpPaths);
                $paths[] = $t;
            }

            $paths = array_reverse($paths);
            foreach ($paths as $p) {
                if ($document = Document::getByPath($p)) {
                    if (empty($types) || in_array($document->getType(), $types)) {
                        $document = $this->nearestPathCache[$cacheKey] = $document;

                        break;
                    }
                } elseif (Model\Site::isSiteRequest()) {
                    // also check for a pretty url in a site
                    $site = Model\Site::getCurrentSite();

                    // undo the changed made by the site detection in self::match()
                    $originalPath = preg_replace('@^' . $site->getRootPath() . '@', '', $p);

                    $sitePrettyDocId = $this->getDao()->getDocumentIdByPrettyUrlInSite($site, $originalPath);
                    if ($sitePrettyDocId) {
                        if ($sitePrettyDoc = Document::getById($sitePrettyDocId)) {
                            $document = $this->nearestPathCache[$cacheKey] = $sitePrettyDoc;

                            break;
                        }
                    }
                }
            }
        }

        if ($document) {
            if (!$ignoreHardlinks) {
                if ($document instanceof Document\Hardlink) {
                    if ($hardLinkedDocument = Document\Hardlink\Service::getNearestChildByPath($document, $path)) {
                        $document = $hardLinkedDocument;
                    } else {
                        $document = Document\Hardlink\Service::wrap($document);
                    }
                }
            }

            return $document;
        }

        return null;
    }

    /**
     * @param int $id
     * @param Request $request
     * @param string $hostUrl
     *
     * @return bool
     *
     * @throws \Exception
     *
     * @internal
     */
    public static function generatePagePreview($id, $request = null, $hostUrl = null)
    {
        $success = false;

        /** @var Page $doc */
        $doc = Document::getById($id);
        if (!$hostUrl) {
            $hostUrl = Config::getSystemConfiguration('documents')['preview_url_prefix'];
            if (empty($hostUrl)) {
                $hostUrl = Tool::getHostUrl(null, $request);
            }
        }

        $url = $hostUrl . $doc->getRealFullPath();
        $tmpFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/screenshot_tmp_' . $doc->getId() . '.png';
        $file = $doc->getPreviewImageFilesystemPath();

        File::mkdir(dirname($file));

        $tool = false;
        if (Chromium::isSupported()) {
            $tool = Chromium::class;
        } elseif (HtmlToImage::isSupported()) {
            $tool = HtmlToImage::class;
        }

        if ($tool) {
            /** @var Chromium|HtmlToImage $tool */
            if ($tool::convert($url, $tmpFile)) {
                $im = \Pimcore\Image::getInstance();
                $im->load($tmpFile);
                $im->scaleByWidth(800);
                $im->save($file, 'jpeg', 85);

                unlink($tmpFile);

                $success = true;
            }
        }

        return $success;
    }
}
