<?php
/*
 * @file
 * Handle Stripe Webhooks for recurring payments.
 */

require_once 'CRM/Core/Page.php';

class CRM_Stripe_Page_Webhook extends CRM_Core_Page {
  function run() {
    try {
      $this->handleEvent();
    } catch (Exception $e) {
      header("HTTP/1.1 500 Internal Server Error");
      CRM_Core_Error::Fatal($e->getMessage());
      exit();
    }
  }

  function handleEvent() {
    // Get the data from Stripe.
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
    if (!$data) {
      throw new Exception("Stripe Callback: cannot json_decode data, exiting. <br /> $data");
    }

    $test_mode = ! $data->livemode;

    $stripe_key = CRM_Core_DAO::singleValueQuery("SELECT pp.user_name FROM civicrm_payment_processor pp INNER JOIN civicrm_payment_processor_type ppt on pp.payment_processor_type_id = ppt.id AND ppt.name  = 'Stripe' WHERE is_test = '$test_mode'");

    require_once ("packages/stripe-php/lib/Stripe.php");
    Stripe::setApiKey($stripe_key);

    // Retrieve Event from Stripe using ID even though we already have the values now.
    // This is for extra security precautions mentioned here: https://stripe.com/docs/webhooks
    $stripe_event_data = Stripe_Event::retrieve($data->id);
    $customer_id = $stripe_event_data->data->object->customer;
    switch($stripe_event_data->type) {
      // Successful recurring payment.
      case 'invoice.payment_succeeded':
        // Get the Stripe charge object.
        $charge = Stripe_Charge::retrieve($stripe_event_data->data->object->charge);
        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $query_params = array(
          1 => array($customer_id, 'String'),
        );
        $rel_info_query = CRM_Core_DAO::executeQuery("SELECT invoice_id, end_time
          FROM civicrm_stripe_subscriptions
          WHERE customer_id = %1",
          $query_params);

        if (!empty($rel_info_query)) {
          $rel_info_query->fetch();
          $invoice_id = $rel_info_query->invoice_id;
          $end_time = $rel_info_query->end_time;
        } else {
          throw new Exception("Error relating this customer ($customer_id) to the one in civicrm_stripe_subscriptions");
        }

        // Compare against now + 24hrs to prevent charging 1 extra day.
        $time_compare = time() + 86400;

        // As of 4.3, contribution_type_id column renamed to financial_type_id.
        $financial_field = 'contribution_type_id';
        $civi_version = CRM_Utils_System::version();
        if ($civi_version >= 4.3) {
          $financial_field = 'financial_type_id';
        }
        // Fetch Civi's info about this recurring object.
        $query_params = array(
          1 => array($invoice_id, 'String'),
        );
        $recur_contrib_query = CRM_Core_DAO::executeQuery("SELECT id, contact_id, currency, contribution_status_id, is_test, {$financial_field}, payment_instrument_id, campaign_id
          FROM civicrm_contribution_recur
          WHERE invoice_id = %1",
          $query_params);

        if (!empty($recur_contrib_query)) {
          $recur_contrib_query->fetch();
        } else {
          throw new Exception("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
        }
        // Build some params.
        $stripe_customer = Stripe_Customer::retrieve($customer_id);
        $recieve_date = date("Y-m-d H:i:s", $charge->created);
        $total_amount = $charge->amount / 100;
        $fee_amount = isset($charge->fee) ? ($charge->fee / 100) : 0;
        $net_amount = $total_amount - $fee_amount;
        $transaction_id = $charge->id;
        $new_invoice_id = $stripe_event_data->data->object->id;
        

        $query_params = array(
          1 => array($invoice_id, 'String'),
        );
        $first_contrib_check = CRM_Core_DAO::singleValueQuery("SELECT id
          FROM civicrm_contribution
          WHERE invoice_id = %1
          AND contribution_status_id = '2'", $query_params);

        if (!empty($first_contrib_check)) {
          $query_params = array(
            1 => array($first_contrib_check, 'Integer'),
          );
          CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution
            SET contribution_status_id = '1'
            WHERE id = %1",
            $query_params);
          $this->sendStartEmails($first_contrib_check, $recur_contrib_query->id);
          return;
        }

        // Create this instance of the contribution for accounting in CiviCRM.
        $query_params = array(
          1 => array($recur_contrib_query->contact_id, 'Integer'),
          2 => array($recur_contrib_query->{$financial_field}, 'Integer'),
          3 => array($recur_contrib_query->payment_instrument_id, 'Integer'),
          4 => array($recieve_date, 'String'),
          5 => array($total_amount, 'String'),
          6 => array($fee_amount, 'String'),
          7 => array($net_amount, 'String'),
          8 => array($transaction_id, 'String'),
          9 => array($new_invoice_id, 'String'),
          10 => array($recur_contrib_query->currency, 'String'),
          11 => array($recur_contrib_query->id, 'Integer'),
          12 => array($recur_contrib_query->is_test, 'Integer'),
        );

        // We have to add campaign_id manually because it could be an integer
        // or it could be NULL and CiviCRM can't validate something that could
        // be either.;
        if (!empty($recur_contrib_query->campaign_id)) {
          // If it's a number, ensure it's an intval to avoid injection attack.
          $campaign_id = intval($recur_contrib_query->campaign_id);
        }
        else {
          $campaign_id = 'NULL';
        }
        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_contribution (
          contact_id, {$financial_field}, payment_instrument_id, receive_date,
          total_amount, fee_amount, net_amount, trxn_id, invoice_id, currency,
          contribution_recur_id, is_test, contribution_status_id, campaign_id
          ) VALUES (
          %1, %2, %3, %4,
          %5, %6, %7, %8, %9, %10,
          %11, %12, '1', $campaign_id)",
          $query_params);
        $contribution_id = CRM_Core_DAO::singleValueQuery("SELECT LAST_INSERT_ID();");
        if ($contribution_id == NULL) {
          throw new Exception("Couldn't get ID for newly created contribution.");
        }

          if (!empty($end_time) && $time_compare > $end_time) {
            $end_date = date("Y-m-d H:i:s", $end_time);
            // Final payment.  Recurring contribution complete.
            $stripe_customer->cancelSubscription();

            $query_params = array(
              1 => array($invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE invoice_id = %1", $query_params);

            $query_params = array(
              1 => array($end_date, 'String'),
              2 => array($invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
              SET end_date = %1, contribution_status_id = '1'
              WHERE invoice_id = %2", $query_params);

            return;
          }

          $contribution = CRM_Contribute_BAO_Contribution::findById($contribution_id);
          $contribution_recur = CRM_Contribute_BAO_ContributionRecur::findById($recur_contrib_query->id);
          $this->sendReceiptEmail($contribution_recur, $contribution);

          // Successful charge & more to come so set recurring contribution status to In Progress.
          $query_params = array(
            1 => array($invoice_id, 'String'),
          );
          if ($recur_contrib_query->contribution_status_id != 5) {
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
              SET contribution_status_id = 5
              WHERE invoice_id = %1", $query_params);

            return;
          }

        break;

      // Failed recurring payment.
      case 'invoice.payment_failed':
        // Get the Stripe charge object.
        $charge = Stripe_Charge::retrieve($stripe_event_data->data->object->charge);
        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $query_params = array(
          1 => array($customer_id, 'String'),
        );
        $invoice_id = CRM_Core_DAO::singleValueQuery("SELECT invoice_id
          FROM civicrm_stripe_subscriptions
          WHERE customer_id = %1", $query_params);
        if (empty($invoice_id)) {
          throw new Exception("Error relating this customer ({$customer_id}) to the one in civicrm_stripe_subscriptions");
        }

        // Fetch Civi's info about this recurring object.
        $query_params = array(
          1 => array($invoice_id, 'String'),
        );
        $recur_contrib_query = CRM_Core_DAO::executeQuery("SELECT id, contact_id, currency, contribution_status_id, is_test, {$financial_field}, payment_instrument_id, campaign_id
          FROM civicrm_contribution_recur
          WHERE invoice_id = %1", $query_params);
        if (!empty($recur_contrib_query)) {
          $recur_contrib_query->fetch();
        } else {
          throw new Exception("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
        }
        // Build some params.
        $recieve_date = date("Y-m-d H:i:s", $charge->created);
        $total_amount = $charge->amount / 100;
        $fee_amount = isset($charge->fee) ? ($charge->fee / 100) : 0;
        $net_amount = $total_amount - $fee_amount;
        $transaction_id = $charge->id;
        if (empty($recur_contrib_query->campaign_id)) {
          $recur_contrib_query->campaign_id = 'NULL';
        }

        // Create this instance of the contribution for accounting in CiviCRM.
        $query_params = array(
          1 => array($recur_contrib_query->contact_id, 'Integer'),
          2 => array($recur_contrib_query->{$financial_field}, 'Integer'),
          3 => array($recur_contrib_query->payment_instrument_id, 'Integer'),
          4 => array($recieve_date, 'String'),
          5 => array($total_amount, 'String'),
          6 => array($fee_amount, 'String'),
          7 => array($net_amount, 'String'),
          8 => array($transaction_id, 'String'),
          9 => array($invoice_id, 'String'),
          10 => array($recur_contrib_query->currency, 'String'),
          11 => array($recur_contrib_query->id, 'Integer'),
          12 => array($recur_contrib_query->is_test, 'Integer'),
          13 => array($recur_contrib_query->campaign_id, 'Integer'),
        );
        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_contribution (
          contact_id, {$financial_field}, payment_instrument_id, receive_date,
          total_amount, fee_amount, net_amount, trxn_id, invoice_id, currency,
          contribution_recur_id, is_test, contribution_status_id, campaign_id
          ) VALUES (
          %1, %2, %3, %4,
          %5, %6, %7, %8, %9, %10,
          %11, %12, '4', %13)",
          $query_params);

          // Failed charge.  Set to status to: Failed.
          if ($recur_contrib_query->contribution_status_id != 4) {
            $query_params = array(
              1 => array($invoice_id, 'String'),
            );
            CRM_Core_DAO::executeQuery("UPDATE civicrm_contribution_recur
              SET contribution_status_id = 4
              WHERE invoice_id = %1", $query_params);

            return;
          }
          else {
            // This has failed more than once.  Now what?
          }

        break;


      //Subscription is cancelled
      case 'customer.subscription.deleted':

        // Find the recurring contribution in CiviCRM by mapping it from Stripe.
        $query_params = array(
            1 => array($customer_id, 'String'),
        );
        $rel_info_query = CRM_Core_DAO::executeQuery("SELECT invoice_id
          FROM civicrm_stripe_subscriptions
          WHERE customer_id = %1",
            $query_params);

        if (!empty($rel_info_query)) {
          $rel_info_query->fetch();

          if (!empty($rel_info_query->invoice_id)) {
            $invoice_id = $rel_info_query->invoice_id;
          } else {
            throw new Exception("Error relating this customer ($customer_id) to the one in civicrm_stripe_subscriptions");
          }
        }

        // Fetch Civi's info about this recurring contribution
        $recur_contribution = civicrm_api3('ContributionRecur', 'get', array(
          'sequential' => 1,
          'return' => "id",
          'invoice_id' => $invoice_id
        ));

        if (!$recur_contribution['id']) {
          throw new Exception("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
        }

        //Cancel the recurring contribution
        $result = civicrm_api3('ContributionRecur', 'cancel', array(
            'sequential' => 1,
            'id' => $recur_contribution['id']
        ));

        //Delete the record from Stripe's subscriptions table
        $query_params = array(
            1 => array($invoice_id, 'String'),
        );
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_stripe_subscriptions
              WHERE invoice_id = %1", $query_params);

        break;


      // One-time donation and per invoice payment.
      case 'charge.succeeded':
        // Not implemented.
        return;
        break;

    }

    parent::run();
  }

  function sendReceiptEmail($contribution_recur, $contribution) {
    $input = array();
    $ids = array(
      'contact' => $contribution->contact_id,
      'contribution' => $contribution->id,
      'paymentProcessor' => $contribution_recur->payment_processor_id,
    );
    $values = array(
      'is_email_receipt' => TRUE,
    );
    $contribution->composeMessageArray($input, $ids, $values, TRUE, FALSE);
  }

  function sendStartEmails($contribution_id, $contribution_recur_id) {
    $contribution = CRM_Contribute_BAO_Contribution::findById($contribution_id);
    $contribution_recur = CRM_Contribute_BAO_ContributionRecur::findById($contribution_recur_id);
    CRM_Contribute_BAO_ContributionPage::recurringNotify(CRM_Core_Payment::RECURRING_PAYMENT_START, $contribution_recur->contact_id, $contribution->contribution_page_id, $contribution_recur);
    $this->sendReceiptEmail($contribution_recur, $contribution);
  }
}
