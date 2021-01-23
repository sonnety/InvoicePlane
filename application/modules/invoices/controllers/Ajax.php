<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * InvoicePlane
 *
 * @author		InvoicePlane Developers & Contributors
 * @copyright	Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license		https://invoiceplane.com/license.txt
 * @link		https://invoiceplane.com
 */

/**
 * Class Ajax.
 */
class Ajax extends Admin_Controller
{
    public $ajax_controller = true;

    public function respond($response): void
    {
        echo json_encode($response);
        exit;
    }

    public function save(): void
    {
        $this->load->model('invoices/mdl_items');
        $this->load->model('invoices/mdl_invoices');
        $this->load->model('units/mdl_units');
        $this->load->model('invoices/mdl_invoice_sumex');

        $invoice_id = $this->input->post('invoice_id');

        $this->mdl_invoices->set_id($invoice_id);

        if ($this->mdl_invoices->run_validation('validation_rules_save_invoice') === false) {
            $this->load->helper('json_error');
            $response = [
                    'success'           => 0,
                    'validation_errors' => json_errors(),
                ];
            $this->respond($response);
        }

        $invoice_status = $this->input->post('invoice_status_id');

        if ($this->input->post('invoice_discount_amount') === '') {
            $invoice_discount_amount = floatval(0);
        } else {
            $invoice_discount_amount = $this->input->post('invoice_discount_amount');
        }

        if ($this->input->post('invoice_discount_percent') === '') {
            $invoice_discount_percent = floatval(0);
        } else {
            $invoice_discount_percent = $this->input->post('invoice_discount_percent');
        }

        // Generate new invoice number if needed
        $invoice_number = $this->input->post('invoice_number');

        if (empty($invoice_number) && $invoice_status != 1) {
            $invoice_group_id = $this->mdl_invoices->get_invoice_group_id($invoice_id);
            $invoice_number = $this->mdl_invoices->get_invoice_number($invoice_group_id);
        }

        $db_array = [
                'invoice_number'           => $invoice_number,
                'invoice_terms'            => $this->input->post('invoice_terms'),
                'invoice_date_created'     => date_to_mysql($this->input->post('invoice_date_created')),
                'invoice_date_due'         => date_to_mysql($this->input->post('invoice_date_due')),
                'invoice_password'         => $this->input->post('invoice_password'),
                'invoice_status_id'        => $invoice_status,
                'payment_method'           => $this->input->post('payment_method'),
                'invoice_discount_amount'  => standardize_amount($invoice_discount_amount),
                'invoice_discount_percent' => standardize_amount($invoice_discount_percent),
            ];

        // check if status changed to sent, the feature is enabled and settings is set to sent
        if ($this->config->item('disable_read_only') === false) {
            if ($invoice_status == get_setting('read_only_toggle')) {
                $db_array['is_read_only'] = 1;
            }
        }

        $this->mdl_invoices->save($invoice_id, $db_array);
        $sumexInvoice = $this->mdl_invoices->where('sumex_invoice', $invoice_id)->get()->num_rows();

        if ($sumexInvoice >= 1) {
            $sumex_array = [
                    'sumex_invoice'        => $invoice_id,
                    'sumex_reason'         => $this->input->post('invoice_sumex_reason'),
                    'sumex_diagnosis'      => $this->input->post('invoice_sumex_diagnosis'),
                    'sumex_treatmentstart' => date_to_mysql($this->input->post('invoice_sumex_treatmentstart')),
                    'sumex_treatmentend'   => date_to_mysql($this->input->post('invoice_sumex_treatmentend')),
                    'sumex_casedate'       => date_to_mysql($this->input->post('invoice_sumex_casedate')),
                    'sumex_casenumber'     => $this->input->post('invoice_sumex_casenumber'),
                    'sumex_observations'   => $this->input->post('invoice_sumex_observations'),
                ];
            $this->mdl_invoice_sumex->save($invoice_id, $sumex_array);
        }

        // Recalculate for discounts
        $this->load->model('invoices/mdl_invoice_amounts');
        $this->mdl_invoice_amounts->calculate($invoice_id);

        $response = [
                'success' => 1,
        ];

        $invoice_status = $this->input->post('invoice_status_id');

        $invoice_discount_amount = (is_numeric($this->input->post('invoice_discount_amount')) ? $this->input->post('invoice_discount_amount') : 0);
        $invoice_discount_percent = (is_numeric($this->input->post('invoice_discount_percent')) ? $this->input->post('invoice_discount_percent') : 0);

        // Generate new invoice number if needed
        $invoice_number = $this->input->post('invoice_number');

        if (empty($invoice_number) && $invoice_status != 1) {
            $invoice_group_id = $this->mdl_invoices->get_invoice_group_id($invoice_id);
            $invoice_number = $this->mdl_invoices->get_invoice_number($invoice_group_id);
        }

        $db_array = [
            'invoice_number'           => $invoice_number,
            'invoice_terms'            => $this->input->post('invoice_terms'),
            'invoice_date_created'     => date_to_mysql($this->input->post('invoice_date_created')),
            'invoice_date_due'         => date_to_mysql($this->input->post('invoice_date_due')),
            'invoice_password'         => $this->input->post('invoice_password'),
            'invoice_status_id'        => $invoice_status,
            'payment_method'           => $this->input->post('payment_method'),
            'invoice_discount_amount'  => standardize_amount($invoice_discount_amount),
            'invoice_discount_percent' => standardize_amount($invoice_discount_percent),
        ];

        $db_array['is_read_only'] = $this->get_read_only_status($invoice_status);

        $this->mdl_invoices->save($invoice_id, $db_array);
        $invoice = $this->mdl_invoices->get_by_id($invoice_id);

        $this->save_sumex($invoice_id);
        $matches = [];
        $response = $this->save_all_items($invoice, $invoice_id, $matches);

        $this->respond($response);
    }

    public function save_invoice_tax_rate(): void
    {
        $this->load->model('invoices/mdl_invoice_tax_rates');

        if ($this->mdl_invoice_tax_rates->run_validation()) {
            $this->mdl_invoice_tax_rates->save();

            $response = [
                'success' => 1,
            ];
        } else {
            $response = [
                'success'           => 0,
                'validation_errors' => $this->mdl_invoice_tax_rates->validation_errors,
            ];
        }

        $this->respond($response);
    }

    public function create(): void
    {
        $this->load->model('invoices/mdl_invoices');

        if ($this->mdl_invoices->run_validation()) {
            $invoice_id = $this->mdl_invoices->create();

            $response = [
                'success'    => 1,
                'invoice_id' => $invoice_id,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        $this->respond($response);
    }

    public function create_recurring(): void
    {
        $this->load->model('invoices/mdl_invoices_recurring');

        if ($this->mdl_invoices_recurring->run_validation()) {
            $this->mdl_invoices_recurring->save();

            $response = [
                'success' => 1,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        echo json_encode($response);
    }

    public function get_item(): void
    {
        $this->load->model('invoices/mdl_items');

        $item = $this->mdl_items->get_by_id($this->input->post('item_id'));

        echo json_encode($item);
    }

    public function modal_create_invoice(): void
    {
        $this->load->module('layout');
        $this->load->model('invoice_groups/mdl_invoice_groups');
        $this->load->model('tax_rates/mdl_tax_rates');
        $this->load->model('clients/mdl_clients');

        $data = [
            'invoice_groups' => $this->mdl_invoice_groups->get()->result(),
            'tax_rates'      => $this->mdl_tax_rates->get()->result(),
            'client'         => $this->mdl_clients->get_by_id($this->input->post('client_id')),
            'clients'        => $this->mdl_clients->get_latest(),
        ];

        $this->layout->load_view('invoices/modal_create_invoice', $data);
    }

    public function modal_create_recurring(): void
    {
        $this->load->module('layout');

        $this->load->model('mdl_invoices_recurring');

        $data = [
            'invoice_id'        => $this->input->post('invoice_id'),
            'recur_frequencies' => $this->mdl_invoices_recurring->recur_frequencies,
        ];

        $this->layout->load_view('invoices/modal_create_recurring', $data);
    }

    public function get_recur_start_date(): void
    {
        $invoice_date = $this->input->post('invoice_date');
        $recur_frequency = $this->input->post('recur_frequency');

        echo increment_user_date($invoice_date, $recur_frequency);
    }

    public function modal_change_client(): void
    {
        $this->load->module('layout');
        $this->load->model('clients/mdl_clients');

        $data = [
            'client_id'  => $this->input->post('client_id'),
            'invoice_id' => $this->input->post('invoice_id'),
            'clients'    => $this->mdl_clients->get_latest(),
        ];

        $this->layout->load_view('invoices/modal_change_client', $data);
    }

    public function change_client(): void
    {
        $this->load->model('invoices/mdl_invoices');
        $this->load->model('clients/mdl_clients');

        // Get the client ID
        $client_id = $this->input->post('client_id');
        $client = $this->mdl_clients->where('ip_clients.client_id', $client_id)->get()->row();

        if (!empty($client)) {
            $invoice_id = $this->input->post('invoice_id');

            $db_array = [
                'client_id' => $client_id,
            ];
            $this->db->where('invoice_id', $invoice_id);
            $this->db->update('ip_invoices', $db_array);

            $response = [
                'success'    => 1,
                'invoice_id' => $invoice_id,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        echo json_encode($response);
    }

    public function modal_copy_invoice(): void
    {
        $this->load->module('layout');

        $this->load->model('invoices/mdl_invoices');
        $this->load->model('invoice_groups/mdl_invoice_groups');
        $this->load->model('tax_rates/mdl_tax_rates');

        $data = [
            'invoice_groups' => $this->mdl_invoice_groups->get()->result(),
            'tax_rates'      => $this->mdl_tax_rates->get()->result(),
            'invoice_id'     => $this->input->post('invoice_id'),
            'invoice'        => $this->mdl_invoices->where('ip_invoices.invoice_id', $this->input->post('invoice_id'))
                ->get()
                ->row(),
        ];

        $this->layout->load_view('invoices/modal_copy_invoice', $data);
    }

    public function copy_invoice(): void
    {
        $this->load->model('invoices/mdl_invoices');
        $this->load->model('invoices/mdl_items');
        $this->load->model('invoices/mdl_invoice_tax_rates');

        if ($this->mdl_invoices->run_validation()) {
            $target_id = $this->mdl_invoices->save();
            $source_id = $this->input->post('invoice_id');

            $this->mdl_invoices->copy_invoice($source_id, $target_id);

            $response = [
                'success'    => 1,
                'invoice_id' => $target_id,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        echo json_encode($response);
    }

    public function modal_create_credit(): void
    {
        $this->load->module('layout');

        $this->load->model('invoices/mdl_invoices');
        $this->load->model('invoice_groups/mdl_invoice_groups');
        $this->load->model('tax_rates/mdl_tax_rates');

        $data = [
            'invoice_groups' => $this->mdl_invoice_groups->get()->result(),
            'tax_rates'      => $this->mdl_tax_rates->get()->result(),
            'invoice_id'     => $this->input->post('invoice_id'),
            'invoice'        => $this->mdl_invoices->where('ip_invoices.invoice_id', $this->input->post('invoice_id'))
                ->get()
                ->row(),
        ];

        $this->layout->load_view('invoices/modal_create_credit', $data);
    }

    public function create_credit(): void
    {
        $this->load->model('invoices/mdl_invoices');
        $this->load->model('invoices/mdl_items');
        $this->load->model('invoices/mdl_invoice_tax_rates');

        if ($this->mdl_invoices->run_validation()) {
            $target_id = $this->mdl_invoices->save();
            $source_id = $this->input->post('invoice_id');

            $this->mdl_invoices->copy_credit_invoice($source_id, $target_id);

            // Set source invoice to read-only
            if ($this->config->item('disable_read_only') == false) {
                $this->mdl_invoices->where('invoice_id', $source_id);
                $this->mdl_invoices->update('ip_invoices', ['is_read_only' => '1']);
            }

            // Set target invoice to credit invoice
            $this->mdl_invoices->where('invoice_id', $target_id);
            $this->mdl_invoices->update('ip_invoices', ['creditinvoice_parent_id' => $source_id]);

            $this->mdl_invoices->where('invoice_id', $target_id);
            $this->mdl_invoices->update('ip_invoice_amounts', ['invoice_sign' => '-1']);

            $response = [
                'success'    => 1,
                'invoice_id' => $target_id,
            ];
        } else {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
        }

        echo json_encode($response);
    }

    /**
     * @param $invoice_id
     */
    public function delete_item($invoice_id): void
    {
        // Default to failure.
        $response['success'] = 0;
        $item_id = $this->input->post('item_id');
        $this->load->model('mdl_invoices');

        // Only continue if no item id was provided
        if (empty($item_id) === true) {
            $this->respond($response);
        }

        $object = $this->mdl_invoices->get_by_id($invoice_id);

        // Only continue if the invoice exists
        if (is_object($object) === false) {
            $this->respond($response);
        }

        // Delete invoice item
        $this->load->model('mdl_items');
        $item = $this->mdl_items->delete($item_id);

        // Check if deletion was successful
        $response['success'] = ($item ? 1 : 0);

        // Mark task as complete from invoiced
        if (isset($item->item_task_id) && $item->item_task_id) {
            $this->load->model('tasks/mdl_tasks');
            $this->mdl_tasks->update_status(3, $item->item_task_id);
        }

        // Return the response
        $this->respond($response);
    }

    /**
     * @param $invoice_status
     *
     * @return mixed
     */
    public function get_read_only_status($invoice_status)
    {
        // check if status changed to sent, the feature is enabled and settings is set to sent
        if ($this->config->item('disable_read_only') !== false) {
            return 0;
        }

        return $invoice_status == get_setting('read_only_toggle') ? 1 : 0;
    }

    /**
     * @param $invoice_id
     *
     * @return bool
     */
    public function save_sumex($invoice_id)
    {
        // Sumex saving
        $sumexInvoice = $this->mdl_invoices->where('sumex_invoice', $invoice_id)->get()->num_rows();

        if ($sumexInvoice === 0) {
            return false;
        }

        $sumex_array = [
            'sumex_invoice'        => $invoice_id,
            'sumex_reason'         => $this->input->post('invoice_sumex_reason'),
            'sumex_diagnosis'      => $this->input->post('invoice_sumex_diagnosis'),
            'sumex_treatmentstart' => date_to_mysql($this->input->post('invoice_sumex_treatmentstart')),
            'sumex_treatmentend'   => date_to_mysql($this->input->post('invoice_sumex_treatmentend')),
            'sumex_casedate'       => date_to_mysql($this->input->post('invoice_sumex_casedate')),
            'sumex_casenumber'     => $this->input->post('invoice_sumex_casenumber'),
            'sumex_observations'   => $this->input->post('invoice_sumex_observations'),
        ];
        $this->mdl_invoice_sumex->save($invoice_id, $sumex_array);
    }

    /**
     * @param $invoice
     * @param $invoice_id
     * @param $matches
     *
     * @return array
     */
    public function save_all_items($invoice, $invoice_id, $matches)
    {
        $items = json_decode($this->input->post('items'));

        //check for JSON error, and respond accordingly.
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->load->helper('json_error');
            $response = [
                'success'           => 0,
                'validation_errors' => json_errors(),
            ];
            $this->respond($response);
        }

        foreach ($items as $item) {
            //Throw errors for broken items before trying to process other things.
            if (empty($item->item_name) && (!empty($item->item_quantity) || !empty($item->item_price))) {
                // Throw an error message and use the form validation for that
                $this->load->library('form_validation');
                $this->form_validation->set_rules('item_name', trans('item'), 'required');
                $this->form_validation->run();

                $response = [
                    'success'           => 0,
                    'validation_errors' => [
                        'item_name' => form_error('item_name', '', ''),
                    ],
                ];

                $this->respond($response);
            }

            // There was a "not empty" check here, but that should be caught with form validation.
            $this->process_item($invoice, $item);
        }

        // Recalculate for discounts
        $this->load->model('invoices/mdl_invoice_amounts');
        $this->mdl_invoice_amounts->calculate($invoice_id);

        $response = [
            'success' => 1,
        ];

        // Save all custom fields
        $this->save_custom_fields($invoice_id, $matches);

        return $response;
    }

    /**
     * @param $invoice
     * @param $item
     */
    public function process_item($invoice, $item): void
    {
        $item->item_quantity = ($item->item_quantity ? standardize_amount($item->item_quantity) : (int) 0);
        $item->item_price = ($item->item_quantity ? standardize_amount($item->item_price) : (int) 0);
        $item->item_discount_amount = ($item->item_discount_amount ? standardize_amount($item->item_discount_amount) : null);
        $item->item_product_id = ($item->item_product_id ? $item->item_product_id : null);
        $item->item_product_unit_id = ($item->item_product_unit_id ? $item->item_product_unit_id : null);

        $item->item_product_unit = $this->mdl_units->get_name($item->item_product_unit_id, $item->item_quantity);

        $item->item_discount_calc = $invoice->invoice_item_discount_calc;

        if (property_exists($item, 'item_date')) {
            $item->item_date = ($item->item_date ? date_to_mysql($item->item_date) : null);
        }

        $item_id = ($item->item_id) ?: null;
        unset($item->item_id);

        $this->save_item_tasks($item);

        $this->mdl_items->save($item_id, $item);
    }

    /**
     * @param $item
     */
    public function save_item_tasks($item): void
    {
        if (!$item->item_task_id) {
            // Not sure what conditions exist that we have to unset a blank or falsey id, but OK. ??
            unset($item->item_task_id);

            return;
        }

        $this->load->model('tasks/mdl_tasks');
        $this->mdl_tasks->update_status(4, $item->item_task_id);
    }

    /**
     * @param $invoice_id
     * @param $matches
     *
     * @return bool
     */
    public function save_custom_fields($invoice_id, $matches)
    {
        if (!$this->input->post('custom')) {
            return false;
        }

        $db_array = [];
        $values = [];

        // Should be refactored to remove else statements, but not going to mess with regex without unit tests.
        // See: https://phpmd.org/rules/cleancode.html
        foreach ($this->input->post('custom') as $custom) {
            if (preg_match("/^(.*)\[\]$/i", $custom['name'], $matches)) {
                $values[$matches[1]][] = $custom['value'];
            } else {
                $values[$custom['name']] = $custom['value'];
            }
        }

        foreach ($values as $key => $value) {
            preg_match("/^custom\[(.*?)\](?:\[\]|)$/", $key, $matches);
            if ($matches) {
                $db_array[$matches[1]] = $value;
            }
        }

        $this->load->model('custom_fields/mdl_invoice_custom');
        $result = $this->mdl_invoice_custom->save_custom($invoice_id, $db_array);

        if ($result !== true) {
            $response = [
                'success'           => 0,
                'validation_errors' => $result,
            ];

            $this->respond($response);
        }

        return true;
    }
}
