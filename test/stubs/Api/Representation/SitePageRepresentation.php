<?php
namespace Omeka\Api\Representation;

class SitePageRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o:SitePage';
    }

    public function getJsonLd()
    {
        $created = [
            '@value' => $this->getDateTime($this->created()),
            '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
        ];
        $modified = null;
        if ($this->modified()) {
            $modified = [
               '@value' => $this->getDateTime($this->modified()),
               '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }
        return [
            'o:slug' => $this->slug(),
            'o:title' => $this->title(),
            'o:is_public' => $this->isPublic(),
            'o:layout' => $this->layout(),
            'o:layout_data' => $this->layoutData() ?? [],
            'o:block' => $this->blocks(),
            'o:site' => $this->site()->getReference(),
            'o:created' => $created,
            'o:modified' => $modified,
        ];
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/site/slug/page/default',
            [
                'site-slug' => $this->site()->slug(),
                'page-slug' => $this->slug(),
                'action' => $action,
            ],
            ['force_canonical' => $canonical]
        );
    }

    /**
     * @return string
     */
    public function slug()
    {
        return $this->resource->getSlug();
    }

    /**
     * @return string
     */
    public function title()
    {
        return $this->resource->getTitle();
    }

    /**
     * Get whether this site page is public or not public.
     *
     * @return bool
     */
    public function isPublic()
    {
        return $this->resource->isPublic();
    }

    /**
     * @return ?string
     */
    public function layout()
    {
        return $this->resource->getLayout();
    }

    /**
     * @return ?array
     */
    public function layoutData()
    {
        return $this->resource->getLayoutData();
    }

    /**
     * Get layout data by key.
     *
     * @param string $key The layout data key
     * @param mixed $default Return this if key does not exist
     * @return mixed
     */
    public function layoutDataValue($key, $default = null)
    {
        $layoutData = $this->resource->getLayoutData();
        return $layoutData[$key] ?? $default;
    }

    /**
     * Get the blocks assigned to this page.
     *
     * @return array
     */
    public function blocks()
    {
        $blocks = [];
        foreach ($this->resource->getBlocks() as $block) {
            $blocks[] = new SitePageBlockRepresentation(
                $block,
                $this->getServiceLocator()
            );
        }
        return $blocks;
    }

    /**
     * @return SiteRepresentation
     */
    public function site()
    {
        return $this->getAdapter('sites')
            ->getRepresentation($this->resource->getSite());
    }

    public function created()
    {
        return $this->resource->getCreated();
    }

    public function modified()
    {
        return $this->resource->getModified();
    }

    public function siteUrl($siteSlug = null, $canonical = false)
    {
        if (!$siteSlug) {
            $siteSlug = $this->site()->slug();
        }
        $url = $this->getViewHelper('Url');
        return $url(
            'site/page',
            [
                'site-slug' => $siteSlug,
                'page-slug' => $this->slug(),
            ],
            ['force_canonical' => $canonical]
        );
    }
}
