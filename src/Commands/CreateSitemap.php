<?php

namespace App\Commands;

use App\Kernel;
use App\Services\ConfigService;
use App\Utils\LocationUtils;
use App\Utils\TemplateResponse;
use DOMDocument;
use DOMException;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class CreateSitemap extends BaseCommand
{

    public function getName(): string
    {
        return 'create-sitemap';
    }

    /**
     * @throws DOMException
     */
    public function handle(array $args): void
    {
        $fileLocation = LocationUtils::getRootLocation();
        $fileLocation .= '/public/sitemap.xml';

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $urlset = $doc->createElement("urlset");
        $urlset->setAttribute("xmlns", "http://www.sitemaps.org/schemas/sitemap/0.9");
        $doc->appendChild($urlset);

        $baseLocation = ConfigService::$APP_URL;
        $homeIndex = Kernel::getHomeIndex();
        $viewsLocation = LocationUtils::getRootLocation() . "/src/views/public/";

        // # get the home index
        {
            $view = $this->sanitizeViewPath("$baseLocation");
            $url = $doc->createElement("url");
            $loc = $doc->createElement("loc", $view);

            $url->appendChild($loc);
            $urlset->appendChild($url);
            $this->setMetaTags("$viewsLocation/$homeIndex/index.twig", $doc, $url);
        }


        // # get all public views
        {
            $viewsIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsLocation));
            $processedViews = [];

            foreach ($viewsIterator as $view) {

                $originalViewPath = $view->getPathname();
                $originalViewPath = str_replace(".", '', $originalViewPath);
                $viewPath = str_replace($viewsLocation, '', $originalViewPath);
                $viewPath = rtrim($viewPath, '/');

                if (
                    $view->isDir() &&
                    !in_array($viewPath, $processedViews) &&
                    file_exists($originalViewPath . "/index.twig") &&
                    $viewPath !== $homeIndex
                ) {

                    $view = $this->sanitizeViewPath("$baseLocation/$viewPath");
                    $url = $doc->createElement("url");
                    $loc = $doc->createElement("loc", $view);

                    $url->appendChild($loc);
                    $urlset->appendChild($url);
                    $this->setMetaTags("$originalViewPath/index.twig", $doc, $url);

                    $processedViews[] = $viewPath;
                }
            }
        }

        // Save XML
        $doc->save($fileLocation);
    }

    private function renderMetaTags(string $viewPath, $keys): array
    {
        $meta_tags = [];
        $html = "";
        $dom = null;

        try {
            $html = TemplateResponse::render($viewPath);
        } catch (LoaderError|RuntimeError|SyntaxError $e) {
            return $meta_tags;
        }

        if (empty($html)) {
            return $meta_tags;
        }

        try {
            $dom = new DOMDocument();
            $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (Exception $e) {
            return $meta_tags;
        }

        $metaTags = $dom->getElementsByTagName('meta');

        for ($i = 0; $i < $metaTags->length; $i++) {
            $metaTag = $metaTags->item($i);
            $name = $metaTag->getAttribute('name');
            $content = $metaTag->getAttribute('content');

            if (in_array($name, $keys)) {
                $meta_tags[$name] = $content;
            }
        }

        return $meta_tags;
    }

    private function sanitizeViewPath(string $viewPath): string
    {
        // Replace all double slashes with single slashes after the domain
        // This preserves the protocol (http:// or https://) while fixing path issues
        if (preg_match('/^(https?:\/\/)(.*)$/', $viewPath, $matches)) {
            $protocol = $matches[1]; // e.g., "https://"
            $rest = $matches[2];     // everything after the protocol
            $rest = preg_replace('/\/+/', '/', $rest); // replace multiple slashes with single slash
            return $protocol . $rest;
        }
        
        // If no protocol found, just replace multiple slashes with single slash
        return preg_replace('/\/+/', '/', $viewPath);
    }

    private function setMetaTags($view, $doc, $url): void
    {
        $customTags = [
            "priority",
            "lastmod",
            "changefreq"
        ];

        $metaTags = $this->renderMetaTags($view, $customTags);

        foreach ($customTags as $tagKey) {
            if (isset($metaTags[$tagKey])) {
                $tag = $doc->createElement($tagKey, $metaTags[$tagKey]);
                $url->appendChild($tag);
            }
        }
    }

}