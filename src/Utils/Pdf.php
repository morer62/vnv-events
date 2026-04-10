<?php

namespace App\Utils;

use Closure;
use Fpdf\Fpdf;

class Pdf extends Fpdf
{
    private Closure $renderHeader;
    private Closure $renderFooter;


    public function __construct()
    {
        parent::__construct();
    }

    public function Header()
    {
        return ($this->renderHeader)($this);
    }

    public function Footer()
    {
        return ($this->renderFooter)($this);
    }

    public function setRenderHeader(Closure $renderHeader): void
    {
        $this->renderHeader = $renderHeader;
    }

    public function setRenderFooter(Closure $renderFooter): void
    {
        $this->renderFooter = $renderFooter;
    }


}