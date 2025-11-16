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

if (!interface_exists('\\DVDoug\\BoxPacker\\Item')) {
    $flagshipAutoload = dirname(__DIR__).'/vendor/autoload.php';
    if (file_exists($flagshipAutoload)) {
        require_once $flagshipAutoload;
    }
}

class FlagshipPackingItem implements \DVDoug\BoxPacker\Item
{
    protected $description;
    protected $width;
    protected $length;
    protected $depth;
    protected $weight;
    protected $keepFlat;

    public function __construct(
        string $description,
        int $width,
        int $length,
        int $depth,
        int $weight,
        bool $keepFlat = false
    ) {
        $this->description = $description;
        $this->width = $width;
        $this->length = $length;
        $this->depth = $depth;
        $this->weight = $weight;
        $this->keepFlat = $keepFlat;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function getKeepFlat(): bool
    {
        return $this->keepFlat;
    }
}
