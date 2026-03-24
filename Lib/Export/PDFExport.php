<?php
/*
 * This file is part of Impresos
 * Copyright (C) 2024  yevea
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Impresos\Lib\Export;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Lib\Export\PDFExport as CorePDFExport;

/**
 * Custom PDF export for PresupuestoCliente that places the totals
 * summary table (Divisa, Neto, Impuestos, Total) right after the
 * product items table, instead of at the bottom of the page.
 *
 * This class overrides the core PDFExport through the FacturaScripts
 * Dinamic layer, so it is automatically used for all PDF exports.
 * Non-PresupuestoCliente documents delegate to the parent behavior.
 */
class PDFExport extends CorePDFExport
{
    const THIN_LINE = 0.1;

    public function addBusinessDocPage($model): bool
    {
        if ($model->modelClassName() !== 'PresupuestoCliente') {
            return parent::addBusinessDocPage($model);
        }

        if (null === $this->format) {
            $this->format = $this->getDocumentFormat($model);
        }

        if ($this->pdf === null) {
            $this->newPage();
        } else {
            $this->pdf->ezNewPage();
            $this->insertedHeader = false;
        }

        // set thin line style so the header separator line is thin
        $this->pdf->setLineStyle(self::THIN_LINE);

        $this->insertHeader($model->idempresa);
        $this->insertBusinessDocHeader($model);
        $this->insertBusinessDocBody($model);
        $this->insertBusinessDocFooter($model);

        return false;
    }

    /**
     * Overrides the default footer to place the totals summary table
     * right after the product items table, instead of jumping to the
     * bottom of the page (INVOICE_TOTALS_Y).
     */
    protected function insertBusinessDocFooter($model)
    {
        if ($model->modelClassName() !== 'PresupuestoCliente') {
            parent::insertBusinessDocFooter($model);
            return;
        }

        $this->pdf->ezText("\n");

        // taxes
        $taxHeaders = [
            'tax' => $this->i18n->trans('tax'),
            'taxbase' => $this->i18n->trans('tax-base'),
            'taxp' => $this->i18n->trans('percentage'),
            'taxamount' => $this->i18n->trans('amount'),
            'taxsurchargep' => $this->i18n->trans('re'),
            'taxsurcharge' => $this->i18n->trans('amount')
        ];
        $taxRows = $this->getTaxesRows($model);
        $taxTableOptions = [
            'cols' => [
                'tax' => ['justification' => 'right'],
                'taxbase' => ['justification' => 'right'],
                'taxp' => ['justification' => 'right'],
                'taxamount' => ['justification' => 'right'],
                'taxsurchargep' => ['justification' => 'right'],
                'taxsurcharge' => ['justification' => 'right']
            ],
            'shadeCol' => [0.95, 0.95, 0.95],
            'shadeHeadingCol' => [0.95, 0.95, 0.95],
            'innerLineThickness' => self::THIN_LINE,
            'outerLineThickness' => self::THIN_LINE,
            'width' => $this->tableWidth
        ];
        if (count($taxRows) > 1) {
            $this->removeEmptyCols($taxRows, $taxHeaders, Tools::number(0));
            $this->pdf->ezTable($taxRows, $taxHeaders, '', $taxTableOptions);
            $this->pdf->ezText("\n");
        }

        // subtotals — placed at the current Y position (right after items)
        $headers = [
            'currency' => $this->i18n->trans('currency'),
            'subtotal' => $this->i18n->trans('subtotal'),
            'dto' => $this->i18n->trans('global-dto'),
            'dto-2' => $this->i18n->trans('global-dto-2'),
            'net' => $this->i18n->trans('net'),
            'taxes' => $this->i18n->trans('taxes'),
            'totalSurcharge' => $this->i18n->trans('re'),
            'totalIrpf' => $this->i18n->trans('retention'),
            'totalSupplied' => $this->i18n->trans('supplied-amount'),
            'total' => $this->i18n->trans('total')
        ];
        $rows = [
            [
                'currency' => $this->getDivisaName($model->coddivisa),
                'subtotal' => Tools::number($model->netosindto != $model->neto ? $model->netosindto : 0),
                'dto' => Tools::number($model->dtopor1) . '%',
                'dto-2' => Tools::number($model->dtopor2) . '%',
                'net' => Tools::number($model->neto),
                'taxes' => Tools::number($model->totaliva),
                'totalSurcharge' => Tools::number($model->totalrecargo),
                'totalIrpf' => Tools::number(0 - $model->totalirpf),
                'totalSupplied' => Tools::number($model->totalsuplidos),
                'total' => '<b>' . Tools::number($model->total) . '</b>'
            ]
        ];
        $this->removeEmptyCols($rows, $headers, Tools::number(0));
        $tableOptions = [
            'cols' => [
                'subtotal' => ['justification' => 'right'],
                'dto' => ['justification' => 'right'],
                'dto-2' => ['justification' => 'right'],
                'net' => ['justification' => 'right'],
                'taxes' => ['justification' => 'right'],
                'totalSurcharge' => ['justification' => 'right'],
                'totalIrpf' => ['justification' => 'right'],
                'totalSupplied' => ['justification' => 'right'],
                'total' => ['justification' => 'right']
            ],
            'shadeCol' => [0.95, 0.95, 0.95],
            'shadeHeadingCol' => [0.95, 0.95, 0.95],
            'innerLineThickness' => self::THIN_LINE,
            'outerLineThickness' => self::THIN_LINE,
            'width' => $this->tableWidth
        ];
        $this->pdf->ezTable($rows, $headers, '', $tableOptions);

        if (isset($model->codcliente)) {
            $this->insertInvoicePayMethod($model);
        }

        if (property_exists($model, 'finoferta') && !empty($model->finoferta)) {
            $this->pdf->ezText(
                "\n" . $this->i18n->trans('expiration') . ': ' . $model->finoferta,
                self::FONT_SIZE
            );
        }

        if (!empty($this->format->texto)) {
            $this->pdf->ezText("\n" . Tools::fixHtml($this->format->texto), self::FONT_SIZE);
        }

        // observations at the end
        if (!empty($model->observaciones)) {
            $this->pdf->ezText("\n" . $this->i18n->trans('observations') . "\n", self::FONT_SIZE);
            $this->newLine();
            $this->pdf->ezText(Tools::fixHtml($model->observaciones) . "\n", self::FONT_SIZE);
        }
    }

    /**
     * Overrides the items table to use thin border lines.
     */
    protected function insertBusinessDocBody($model)
    {
        if ($model->modelClassName() !== 'PresupuestoCliente') {
            parent::insertBusinessDocBody($model);
            return;
        }

        $headers = [];
        $tableOptions = [
            'cols' => [],
            'shadeCol' => [0.95, 0.95, 0.95],
            'shadeHeadingCol' => [0.95, 0.95, 0.95],
            'innerLineThickness' => self::THIN_LINE,
            'outerLineThickness' => self::THIN_LINE,
            'width' => $this->tableWidth
        ];

        foreach ($this->getLineHeaders() as $key => $value) {
            $headers[$key] = $value['title'];
            if (in_array($value['type'], ['number', 'percentage'], true)) {
                $tableOptions['cols'][$key] = ['justification' => 'right'];
            }
        }

        $tableData = [];
        foreach ($model->getlines() as $line) {
            $data = [];
            foreach ($this->getLineHeaders() as $key => $value) {
                if (property_exists($line, 'mostrar_precio') &&
                    $line->mostrar_precio === false &&
                    in_array($key, ['pvpunitario', 'dtopor', 'dtopor2', 'pvptotal', 'iva', 'recargo', 'irpf'], true)) {
                    continue;
                }

                if ($key === 'referencia') {
                    $data[$key] = empty($line->{$key}) ? Tools::fixHtml($line->descripcion) : Tools::fixHtml($line->{$key} . " - " . $line->descripcion);
                } elseif ($key === 'cantidad' && property_exists($line, 'mostrar_cantidad')) {
                    $data[$key] = $line->mostrar_cantidad ? $line->{$key} : '';
                } elseif ($value['type'] === 'percentage') {
                    $data[$key] = Tools::number($line->{$key}) . '%';
                } elseif ($value['type'] === 'number') {
                    $data[$key] = Tools::number($line->{$key});
                } else {
                    $data[$key] = $line->{$key};
                }
            }

            $tableData[] = $data;

            if (property_exists($line, 'salto_pagina') && $line->salto_pagina) {
                $this->removeEmptyCols($tableData, $headers, Tools::number(0));
                $this->pdf->ezTable($tableData, $headers, '', $tableOptions);
                $tableData = [];
                $this->pdf->ezNewPage();
            }
        }

        if (false === empty($tableData)) {
            $this->removeEmptyCols($tableData, $headers, Tools::number(0));
            $this->pdf->ezTable($tableData, $headers, '', $tableOptions);
        }
    }

    /**
     * Overrides the payment method table to use thin border lines.
     */
    protected function insertInvoicePayMethod($invoice)
    {
        $headers = [
            'method' => $this->i18n->trans('payment-method'),
            'expiration' => $this->i18n->trans('expiration')
        ];

        $expiration = $invoice->finoferta ?? '';
        $rows = [
            ['method' => $this->getBankData($invoice), 'expiration' => $expiration]
        ];

        $tableOptions = [
            'cols' => [
                'method' => ['justification' => 'left'],
                'expiration' => ['justification' => 'right']
            ],
            'shadeCol' => [0.95, 0.95, 0.95],
            'shadeHeadingCol' => [0.95, 0.95, 0.95],
            'innerLineThickness' => self::THIN_LINE,
            'outerLineThickness' => self::THIN_LINE,
            'width' => $this->tableWidth
        ];
        $this->pdf->ezText("\n");
        $this->pdf->ezTable($rows, $headers, '', $tableOptions);
    }

    protected function newLine()
    {
        $posY = $this->pdf->y + 5;
        $this->pdf->setLineStyle(self::THIN_LINE);
        $this->pdf->line(self::CONTENT_X, $posY, $this->tableWidth + self::CONTENT_X, $posY);
    }
}
