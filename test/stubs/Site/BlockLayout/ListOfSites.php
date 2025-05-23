<?php
namespace Omeka\Site\BlockLayout;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\View\Renderer\PhpRenderer;

class ListOfSites extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    protected $defaults = [
        'sort' => 'alpha',
        'limit' => null,
        'pagination' => false,
        'summaries' => true,
        'thumbnails' => true,
        'exclude_current' => true,
    ];

    public function getLabel()
    {
        return 'List of sites'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        $data = $block ? $block->data() + $this->defaults : $this->defaults;

        $form = new Form();
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][sort]',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Sort', // @translate
                'value_options' => [
                    'alpha' => 'Alphabetical', // @translate
                    'oldest' => 'Oldest first', // @translate
                    'newest' => 'Newest first', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'list-of-sites-sort',
            ],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][limit]',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Max number of sites', // @translate
                'info' => 'An empty value means no limit.', // @translate
            ],
            'attributes' => [
                'id' => 'list-of-sites-limit',
                'placeholder' => 'Unlimited', // @translate
                'min' => 0,
            ],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][pagination]',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Pagination', // @translate
                'info' => 'Show pagination (only if a limit is set)', // @translate
            ],
            'attributes' => [
                'id' => 'list-of-sites-pagination',
            ],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][summaries]',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Show summaries', // @translate
            ],
            'attributes' => [
                'id' => 'list-of-sites-summaries',
            ],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][thumbnails]',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Show thumbnails', // @translate
            ],
            'attributes' => [
                'id' => 'list-of-sites-thumbnails',
            ],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][exclude_current]',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Exclude current site', // @translate
            ],
            'attributes' => [
                'id' => 'list-of-sites-exclude-current',
            ],
        ]);

        $form->setData([
            'o:block[__blockIndex__][o:data][sort]' => $data['sort'],
            'o:block[__blockIndex__][o:data][limit]' => $data['limit'],
            'o:block[__blockIndex__][o:data][pagination]' => $data['pagination'],
            'o:block[__blockIndex__][o:data][summaries]' => $data['summaries'],
            'o:block[__blockIndex__][o:data][thumbnails]' => $data['thumbnails'],
            'o:block[__blockIndex__][o:data][exclude_current]' => $data['exclude_current'],
        ]);

        return $view->formCollection($form, false);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block, $templateViewScript = 'common/block-layout/list-of-sites')
    {
        $sort = $block->dataValue('sort', $this->defaults['sort']);
        $limit = $block->dataValue('limit', $this->defaults['limit']);
        $pagination = $limit && $block->dataValue('pagination', $this->defaults['pagination']);
        $summaries = $block->dataValue('summaries', $this->defaults['summaries']);
        $thumbnails = $block->dataValue('thumbnails', $this->defaults['thumbnails']);
        $excludeCurrent = $block->dataValue('exclude_current', $this->defaults['exclude_current']);

        $data = [];
        if ($pagination) {
            $currentPage = $view->params()->fromQuery('page', 1);
            $data['page'] = $currentPage;
            $data['per_page'] = $limit;
        } elseif ($limit) {
            $data['limit'] = $limit;
        }

        if ($excludeCurrent) {
            $data['exclude_id'] = $block->page()->site()->id();
        }

        switch ($sort) {
            case 'oldest':
                $data['sort_by'] = 'created';
                break;
            case 'newest':
                $data['sort_by'] = 'created';
                $data['sort_order'] = 'desc';
                break;
            default:
            case 'alpha':
                $data['sort_by'] = 'title';
                break;
        }

        $response = $view->api()->search('sites', $data);

        if ($pagination) {
            $totalCount = $response->getTotalResults();
            $view->pagination(null, $totalCount, $currentPage, $limit);
        }

        $sites = $response->getContent();

        return $view->partial($templateViewScript, [
            'block' => $block,
            'sites' => $sites,
            'pagination' => $pagination,
            'summaries' => $summaries,
            'thumbnails' => $thumbnails,
        ]);
    }
}
