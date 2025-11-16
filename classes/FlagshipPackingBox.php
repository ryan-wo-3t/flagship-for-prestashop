<?php
/**
 * 2007-2019 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!interface_exists('\\DVDoug\\BoxPacker\\Box')) {
    $flagshipAutoload = dirname(__DIR__).'/vendor/autoload.php';
    if (file_exists($flagshipAutoload)) {
        require_once $flagshipAutoload;
    }
}

class FlagshipPackingBox implements \DVDoug\BoxPacker\Box
{
    protected $reference;
    protected $outerWidth;
    protected $outerLength;
    protected $outerDepth;
    protected $innerWidth;
    protected $innerLength;
    protected $innerDepth;
    protected $emptyWeight;
    protected $maxWeight;

    public function __construct(
        string $reference,
        int $outerWidth,
        int $outerLength,
        int $outerDepth,
        int $emptyWeight,
        int $maxWeight
    ) {
        $this->reference = $reference;
        $this->outerWidth = $outerWidth;
        $this->outerLength = $outerLength;
        $this->outerDepth = $outerDepth;
        $this->innerWidth = $outerWidth;
        $this->innerLength = $outerLength;
        $this->innerDepth = $outerDepth;
        $this->emptyWeight = $emptyWeight;
        $this->maxWeight = $maxWeight;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getOuterWidth(): int
    {
        return $this->outerWidth;
    }

    public function getOuterLength(): int
    {
        return $this->outerLength;
    }

    public function getOuterDepth(): int
    {
        return $this->outerDepth;
    }

    public function getEmptyWeight(): int
    {
        return $this->emptyWeight;
    }

    public function getInnerWidth(): int
    {
        return $this->innerWidth;
    }

    public function getInnerLength(): int
    {
        return $this->innerLength;
    }

    public function getInnerDepth(): int
    {
        return $this->innerDepth;
    }

    public function getMaxWeight(): int
    {
        return $this->maxWeight;
    }
}
