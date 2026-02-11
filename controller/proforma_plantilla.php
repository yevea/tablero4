<?php
/*
 * This file is part of proforma_plantilla
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

require_once 'plugins/facturacion_base/controller/ventas_imprimir.php';

/**
 * Controlador para generar facturas proforma de presupuestos con una plantilla
 * que incluye los totales (Dto., Neto, IVA, Total) como últimas filas dentro
 * de la tabla de líneas, en lugar de mostrarlos al pie de la página.
 */
class proforma_plantilla extends ventas_imprimir
{

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Factura proforma plantilla', 'ventas');
    }

    protected function private_core()
    {
        $this->init();

        if (isset($_REQUEST['presupuesto']) && isset($_REQUEST['id'])) {
            $pre = new presupuesto_cliente();
            $this->documento = $pre->get($_REQUEST['id']);
            if ($this->documento) {
                $cliente = new cliente();
                $this->cliente = $cliente->get($this->documento->codcliente);
            }

            if (isset($_POST['email'])) {
                $this->enviar_email('presupuesto');
            } else {
                $this->generar_pdf_presupuesto_plantilla();
            }
        }
    }

    protected function share_extensions()
    {
        $extensiones = array(
            array(
                'name' => 'imprimir_presupuesto_plantilla',
                'page_from' => __CLASS__,
                'page_to' => 'ventas_presupuesto',
                'type' => 'pdf',
                'text' => '<span class="glyphicon glyphicon-print"></span>&nbsp; Factura proforma de plantilla',
                'params' => '&presupuesto=TRUE'
            ),
        );
        foreach ($extensiones as $ext) {
            $fsext = new fs_extension($ext);
            if (!$fsext->save()) {
                $this->new_error_msg('Error al guardar la extensión ' . $ext['name']);
            }
        }
    }

    /**
     * Genera el PDF del presupuesto con totales integrados en la tabla de líneas.
     */
    public function generar_pdf_presupuesto_plantilla($archivo = FALSE)
    {
        if (!$archivo) {
            /// desactivamos la plantilla HTML
            $this->template = FALSE;
        }

        /// Creamos el PDF y escribimos sus metadatos
        $pdf_doc = new fs_pdf();
        $pdf_doc->pdf->addInfo('Title', 'Factura proforma ' . $this->documento->codigo);
        $pdf_doc->pdf->addInfo('Subject', 'Factura proforma de presupuesto ' . $this->documento->codigo);
        $pdf_doc->pdf->addInfo('Author', $this->empresa->nombre);

        $lineas = $this->documento->get_lineas();
        $lineas_iva = $pdf_doc->get_lineas_iva($lineas);
        if ($lineas) {
            $linea_actual = 0;
            $pagina = 1;

            /// imprimimos las páginas necesarias
            while ($linea_actual < count($lineas)) {
                $lppag = 35;

                /// salto de página
                if ($linea_actual > 0) {
                    $pdf_doc->pdf->ezNewPage();
                }

                $pdf_doc->generar_pdf_cabecera($this->empresa, $lppag);
                $this->generar_pdf_datos_cliente($pdf_doc, $lppag);

                $es_ultima = $this->es_ultima_pagina($lineas, $linea_actual, $lppag);
                $this->generar_pdf_lineas_plantilla($pdf_doc, $lineas, $linea_actual, $lppag, $lineas_iva, $es_ultima);

                $pdf_doc->set_y(80);
                $pdf_doc->pdf->addText(10, 10, 8, $pdf_doc->center_text('Página ' . $pagina . '/' . $this->numpaginas, 250));
                $pagina++;
            }
        } else {
            $pdf_doc->pdf->ezText('¡' . ucfirst(FS_PRESUPUESTO) . ' sin líneas!', 20);
        }

        if ($archivo) {
            if (!file_exists('tmp/' . FS_TMP_NAME . 'enviar')) {
                mkdir('tmp/' . FS_TMP_NAME . 'enviar');
            }

            $pdf_doc->save('tmp/' . FS_TMP_NAME . 'enviar/' . $archivo);
        } else {
            $pdf_doc->show('proforma_' . $this->documento->codigo . '.pdf');
        }
    }

    /**
     * Comprueba si la página actual será la última según las líneas restantes.
     */
    private function es_ultima_pagina(&$lineas, $linea_actual, $lppag)
    {
        $lineas_size = 0;
        for ($i = $linea_actual; $i < count($lineas); $i++) {
            $lineas_size += $this->get_linea_size($lineas[$i]->referencia . ' ' . $lineas[$i]->descripcion);
            if ($lineas_size > $lppag) {
                return FALSE;
            }
        }
        return TRUE;
    }

    /**
     * Genera las líneas del documento en la tabla PDF e incluye los totales
     * (Dto., Neto, IVA, Total) como filas finales de la misma tabla.
     */
    protected function generar_pdf_lineas_plantilla(&$pdf_doc, &$lineas, &$linea_actual, &$lppag, &$lineas_iva, $es_ultima)
    {
        /// calculamos el número de páginas
        if (!isset($this->numpaginas)) {
            $this->numpaginas = 1;
            $linea_a = 0;
            $lineas_size = 0;
            while ($linea_a < count($lineas)) {
                $lineas_size += $this->get_linea_size($lineas[$linea_a]->referencia . ' ' . $lineas[$linea_a]->descripcion);
                if ($lineas_size > $lppag) {
                    $this->numpaginas++;
                    $lineas_size = $this->get_linea_size($lineas[$linea_a]->referencia . ' ' . $lineas[$linea_a]->descripcion);
                }

                $linea_a++;
            }
        }

        /// leemos las líneas para ver si hay que mostrar los tipos de iva, re o irpf
        $lineas_size = 0;
        $dec_cantidad = 0;
        $iva = $re = $irpf = FALSE;
        $multi_iva = $multi_re = $multi_irpf = FALSE;
        $this->impresion['print_dto'] = FALSE;
        for ($i = $linea_actual; $i < count($lineas) && $i < $linea_actual + $lppag; $i++) {
            while ($dec_cantidad < 5 && $lineas[$i]->cantidad != round($lineas[$i]->cantidad, $dec_cantidad)) {
                $dec_cantidad++;
            }

            if ($lineas[$i]->dtopor != 0) {
                $this->impresion['print_dto'] = TRUE;
            }

            if ($iva === FALSE) {
                $iva = $lineas[$i]->iva;
            } else if ($lineas[$i]->iva != $iva) {
                $multi_iva = TRUE;
            }

            if ($re === FALSE) {
                $re = $lineas[$i]->recargo;
            } else if ($lineas[$i]->recargo != $re) {
                $multi_re = TRUE;
            }

            if ($irpf === FALSE) {
                $irpf = $lineas[$i]->irpf;
            } else if ($lineas[$i]->irpf != $irpf) {
                $multi_irpf = TRUE;
            }

            $lineas_size += $this->get_linea_size($lineas[$i]->referencia . ' ' . $lineas[$i]->descripcion);
            if ($lineas_size > $lppag) {
                $lppag = $i - $linea_actual;
            }
        }

        /*
         * Creamos la tabla con las lineas del documento
         */
        $pdf_doc->new_table();
        $table_header = array(
            'descripcion' => '<b>Ref. + Descripción</b>',
            'cantidad' => '<b>Cant.</b>',
            'pvp' => '<b>Precio</b>',
        );

        if ($this->impresion['print_dto']) {
            $table_header['dto'] = '<b>Dto.</b>';
        }

        if ($multi_iva) {
            $table_header['iva'] = '<b>' . FS_IVA . '</b>';
        }

        if ($multi_re) {
            $table_header['re'] = '<b>R.E.</b>';
        }

        if ($multi_irpf) {
            $table_header['irpf'] = '<b>' . FS_IRPF . '</b>';
        }

        $table_header['importe'] = '<b>Importe</b>';
        $pdf_doc->add_table_header($table_header);

        for ($i = $linea_actual; (($linea_actual < ($lppag + $i)) && ($linea_actual < count($lineas)));) {
            $descripcion = fs_fix_html($lineas[$linea_actual]->descripcion);
            if (!is_null($lineas[$linea_actual]->referencia) && $this->impresion['print_ref']) {
                $descripcion = '<b>' . $lineas[$linea_actual]->referencia . '</b> ' . $descripcion;
            }

            /// ¿El articulo tiene trazabilidad?
            $descripcion .= $this->generar_trazabilidad($lineas[$linea_actual]);

            $due_lineas = $this->fbase_calc_desc_due([$lineas[$linea_actual]->dtopor, $lineas[$linea_actual]->dtopor2, $lineas[$linea_actual]->dtopor3, $lineas[$linea_actual]->dtopor4]);

            $fila = array(
                'cantidad' => $this->show_numero($lineas[$linea_actual]->cantidad, $dec_cantidad),
                'descripcion' => $descripcion,
                'pvp' => $this->show_precio($lineas[$linea_actual]->pvpunitario, $this->documento->coddivisa, TRUE, FS_NF0_ART),
                'dto' => $this->show_numero($due_lineas) . " %",
                'iva' => $this->show_numero($lineas[$linea_actual]->iva) . " %",
                're' => $this->show_numero($lineas[$linea_actual]->recargo) . " %",
                'irpf' => $this->show_numero($lineas[$linea_actual]->irpf) . " %",
                'importe' => $this->show_precio($lineas[$linea_actual]->pvptotal, $this->documento->coddivisa)
            );

            if ($lineas[$linea_actual]->dtopor == 0) {
                $fila['dto'] = '';
            }

            if ($lineas[$linea_actual]->recargo == 0) {
                $fila['re'] = '';
            }

            if ($lineas[$linea_actual]->irpf == 0) {
                $fila['irpf'] = '';
            }

            if (!$lineas[$linea_actual]->mostrar_cantidad) {
                $fila['cantidad'] = '';
            }

            if (!$lineas[$linea_actual]->mostrar_precio) {
                $fila['pvp'] = '';
                $fila['dto'] = '';
                $fila['iva'] = '';
                $fila['re'] = '';
                $fila['irpf'] = '';
                $fila['importe'] = '';
            }

            $pdf_doc->add_table_row($fila);
            $linea_actual++;
        }

        /*
         * Si es la última página, añadimos los totales como filas de la tabla
         * en vez de mostrarlos al pie de la página.
         */
        if ($es_ultima) {
            /// Fila separadora vacía
            $fila_vacia = array('descripcion' => '', 'cantidad' => '', 'pvp' => '', 'importe' => '');
            $pdf_doc->add_table_row($fila_vacia);

            /// ¿Hay descuento por documento?
            $due_totales = 0;
            if (isset($this->documento->dtopor1)) {
                $due_totales = $this->fbase_calc_desc_due([$this->documento->dtopor1, $this->documento->dtopor2, $this->documento->dtopor3, $this->documento->dtopor4, $this->documento->dtopor5]);
            }

            /// Fila Dto.
            if ($due_totales > 0) {
                $fila_dto = array(
                    'descripcion' => '<b>Dto.</b>',
                    'cantidad' => '',
                    'pvp' => '',
                    'importe' => $this->show_numero($due_totales) . ' %'
                );
                $pdf_doc->add_table_row($fila_dto);
            }

            /// Fila Neto
            $fila_neto = array(
                'descripcion' => '<b>Neto</b>',
                'cantidad' => '',
                'pvp' => '',
                'importe' => $this->show_precio($this->documento->neto, $this->documento->coddivisa)
            );
            $pdf_doc->add_table_row($fila_neto);

            /// Filas de IVA
            foreach ($lineas_iva as $li) {
                $imp = $this->impuesto->get($li['codimpuesto']);
                $titulo_iva = $imp ? $imp->descripcion : FS_IVA . ' ' . $li['iva'] . '%';
                $total_iva = $li['totaliva'] * (100 - $due_totales) / 100;

                $texto_iva = $this->show_precio($total_iva, $this->documento->coddivisa);
                if ($li['totalrecargo'] != 0) {
                    $totalrecargo = $li['totalrecargo'] * (100 - $due_totales) / 100;
                    $texto_iva .= ' (R.E. ' . $li['recargo'] . '%: ' . $this->show_precio($totalrecargo, $this->documento->coddivisa) . ')';
                }

                $fila_iva = array(
                    'descripcion' => '<b>' . $titulo_iva . '</b>',
                    'cantidad' => '',
                    'pvp' => '',
                    'importe' => $texto_iva
                );
                $pdf_doc->add_table_row($fila_iva);
            }

            /// Fila IRPF si aplica
            if ($this->documento->totalirpf != 0) {
                $fila_irpf = array(
                    'descripcion' => '<b>' . FS_IRPF . ' ' . $this->documento->irpf . '%</b>',
                    'cantidad' => '',
                    'pvp' => '',
                    'importe' => $this->show_precio($this->documento->totalirpf, $this->documento->coddivisa)
                );
                $pdf_doc->add_table_row($fila_irpf);
            }

            /// Fila Total
            $fila_total = array(
                'descripcion' => '<b>TOTAL</b>',
                'cantidad' => '',
                'pvp' => '',
                'importe' => '<b>' . $this->show_precio($this->documento->total, $this->documento->coddivisa) . '</b>'
            );
            $pdf_doc->add_table_row($fila_total);
        }

        $pdf_doc->save_table(
            array(
                'fontSize' => 8,
                'cols' => array(
                    'cantidad' => array('justification' => 'right'),
                    'pvp' => array('justification' => 'right'),
                    'dto' => array('justification' => 'right'),
                    'iva' => array('justification' => 'right'),
                    're' => array('justification' => 'right'),
                    'irpf' => array('justification' => 'right'),
                    'importe' => array('justification' => 'right')
                ),
                'width' => 520,
                'shaded' => 1,
                'shadeCol' => array(0.95, 0.95, 0.95),
                'lineCol' => array(0.3, 0.3, 0.3),
            )
        );

        /// ¿Última página? Mostramos observaciones
        if ($linea_actual == count($lineas) && $this->documento->observaciones != '') {
            $pdf_doc->pdf->ezText("\n" . fs_fix_html($this->documento->observaciones), 9);
        }
    }
}
