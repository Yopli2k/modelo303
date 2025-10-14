<?php
/**
 * This file is part of Modelo303 plugin for FacturaScripts
 * Copyright (C) 2017-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
namespace FacturaScripts\Plugins\Modelo303\Model\Join;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Model\Base\JoinModel;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;

/**
 * Auxiliary model to load a list of accounting entries with VAT
 *
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 * @author Carlos García Gómez           <carlos@facturascripts.com>
 *
 * @property float $baseimponible
 * @property string $documento
 * @property string $codserie
 * @property float $cuotaiva
 * @property float $cuotarecargo
 * @property string $factura
 * @property float $iva
 * @property float $recargo
 */
class PartidaImpuesto extends JoinModel
{
    /**
     * Reset the values of all model view properties.
     */
    public function clear(): void
    {
        parent::clear();
        $this->baseimponible = 0.00;
        $this->iva = 0.00;
        $this->cuotaiva = 0.00;
        $this->recargo = 0.00;
        $this->cuotarecargo = 0.00;
    }

    /**
     * Get the invoice related to this accounting entry.
     *
     * @return BusinessDocument
     */
    protected function getFactura(): BusinessDocument
    {
        $idasiento = $this->idasiento ?? 0;
        $facturaCliente = new FacturaCliente();
        if (empty($idasiento)) {
            return $facturaCliente;
        }

        $where = [new DataBaseWhere('idasiento', $idasiento)];
        if ($facturaCliente->loadWhere($where)) {
            return $facturaCliente;
        }

        $facturaProveedor = new FacturaProveedor();
        $facturaProveedor->loadWhere($where);
        return $facturaProveedor;
    }

    /**
     * List of fields or columns to select clausule
     */
    protected function getFields(): array
    {
        return [
            'codejercicio' => 'asientos.codejercicio',
            'fecha' => 'asientos.fecha',
            'idasiento' => 'asientos.idasiento',

            'baseimponible' => 'partidas.baseimponible',
            'cifnif' => 'partidas.cifnif',
            'codcontrapartida' => 'partidas.codcontrapartida',
            'concepto' => 'partidas.concepto',
            'codserie' => 'partidas.codserie',
            'documento' => 'partidas.documento',
            'factura' => 'partidas.factura',
            'idcontrapartida' => 'partidas.idcontrapartida',
            'idpartida' => 'partidas.idpartida',
            'iva' => 'partidas.iva',
            'numero' => 'asientos.numero',
            'recargo' => 'partidas.recargo',
            'debe' => 'partidas.debe',
            'haber' => 'partidas.haber',

            'codcuentaesp' => 'COALESCE(subcuentas.codcuentaesp, cuentas.codcuentaesp)',
        ];
    }

    /**
     * List of tables related to from clausule
     */
    protected function getSQLFrom(): string
    {
        return 'asientos'
            . ' INNER JOIN partidas ON partidas.idasiento = asientos.idasiento'
            . ' INNER JOIN subcuentas ON subcuentas.idsubcuenta = partidas.idsubcuenta'
            . ' INNER JOIN cuentas ON cuentas.idcuenta = subcuentas.idcuenta'
            . ' LEFT JOIN series ON series.codserie = partidas.codserie';
    }

    /**
     * List of tables required for the execution of the view.
     */
    protected function getTables(): array
    {
        return [
            'asientos',
            'partidas',
            'subcuentas',
            'cuentas',
            'series',
        ];
    }

    /**
     * Assign the values of the $data array to the model view properties.
     *
     * @param array $data
     */
    protected function loadFromData(array $data): void
    {
        parent::loadFromData($data);

        // Si tenemos IVA y recargo en el mismo movimiento,
        // no sabemos cuál es cuál, asi que los calculamos
        // a partir de la base imponible.
        if ($this->iva > 0 && $this->recargo > 0) {
            $this->cuotaiva = $this->baseimponible * ($this->iva / 100.0);
            $this->cuotarecargo = $this->baseimponible * ($this->recargo / 100.0);
            $diff = round($this->getCuota($data['debe'], $data['haber']) - ($this->cuotaiva + $this->cuotarecargo), 2);
            if ($diff !== 0.0) {
                $this->cuotaiva +=  $diff; // Ajustamos la cuota de IVA
            }
        } elseif ($this->iva > 0) {        // Solo tenemos IVA
            $this->cuotaiva = $this->getCuota($data['debe'], $data['haber']);
            $this->cuotarecargo = 0.0;
        } else {                           // Solo tenemos recargo
            $this->cuotarecargo = $this->getCuota($data['debe'], $data['haber']);
            $this->cuotaiva = 0.0;
        }

        // si el campo factura está vacío, buscamos la factura con este asiento
        if (empty($this->factura)) {
            $this->setInvoiceData();
        }
    }

    /**
     * Get the VAT or surcharge amount from the debit or credit value.
     *
     * @param $debe
     * @param $haber
     * @return float
     */
    private function getCuota($debe, $haber): float
    {
        return empty($debe)
            ? (float)$haber ?? 0.0
            : (float)$debe  ?? 0.0;
    }

    /**
     * Set invoice data (factura, documento, codserie) if available.
     *
     * @return void
     */
    private function setInvoiceData(): void
    {
        $factura = $this->getFactura();
        if ($factura->id()) {
            $this->factura = $factura->numero;
            $this->documento = $factura->codigo;
            $this->codserie = $factura->codserie;
        }
    }
}
