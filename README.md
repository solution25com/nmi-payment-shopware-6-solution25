![NMI](https://github.com/user-attachments/assets/a8cf2db5-cd8b-4989-a8cc-53903b680761)

# NMI Payments

## Introduction

The NMI plugin allows Shopware stores to securely process payments using the NMI payment gateway. It supports credit card and ACH transactions, giving store owners flexibility in handling payments. The plugin also enables order authorization, refunds, and saved cards for faster checkout.

### Key Features

- **Credit Card Capture**: Securely process credit card payments via NMI’s PCI-compliant payment gateway.
- **ACH Capture**: Enable customers to make payments directly from their bank accounts using eCheck (ACH).
- **Authorize and Capture**: Allows payment authorization before capturing funds, giving admins control over order approval.
- **COLI (Cancel Order by Line Item)**: Admins can cancel individual items within an order, enabling more precise returns and inventory management.
- **Refunds**: Easily process full or partial refunds for orders, providing a smooth customer service experience.
- **Mixed Cards**: Supports customers purchasing both standard products and subscription items using a single card.
- **Save Card Feature**: Enables customers to securely store their credit card information for faster future purchases.

The plugin includes advanced configuration options, such as API key management for live and sandbox environments, webhook signing, and 3D Secure verification for added payment security.

## Get Started

### Installation & Activation

1. **Download**

## Git

- Clone the Plugin Repository:
- Open your terminal and run the following command in your Shopware 6 custom plugins directory (usually located at custom/plugins/):
  ```
  git clone https://github.com/solution25com/nmi-payment-shopware-6-solution25.git
  ```

  ## Packagist
   ```
    composer require solution25/nmi-payment
    ```

2. **Install the Plugin in Shopware 6**

- Log in to your Shopware 6 Administration panel.
- Navigate to Extensions > My Extensions.
- Locate the newly cloned plugin and click Install.

3. **Activate the Plugin**

- After installation, click Activate to enable the plugin.
- In your Shopware Admin, go to Settings > System > Plugins.
- Upload or install the “NMI” plugin.
- Once installed, toggle the plugin to activate it.

4. **Verify Installation**

- After activation, you will see NMI in the list of installed plugins.
- The plugin name, version, and installation date should appear as shown in the screenshot below.
![Group 7926](https://github.com/user-attachments/assets/224dddd0-1735-4824-970c-8d5c85282793)

## Plugin Configuration

1. **Access Plugin Settings**
- Go to Settings > System > Plugins.
- Locate NMI and click the three dots (...) icon or the plugin name to open its settings.

2. **General Settings**

   Configure the following settings:
- **API Key for Live**: Required for live transactions.
- **API Public Key Live**: Public key for authentication in the live environment.
- **API Key for Sandbox**: Required for testing transactions in the sandbox environment.
- **API Public Key Sandbox**: Public key for authentication in sandbox mode.
- **Signing Key**: Used for secure webhook communication.
- **Authorize and Capture**: When enabled, transactions must be manually approved before funds are captured.
![Group 7971](https://github.com/user-attachments/assets/b0111a6f-b720-4d0a-90d0-2f6468896846)
![Group 7972](https://github.com/user-attachments/assets/0fc0bfbb-2ff9-45fa-bbc3-91b942841845)

Once the plugin is installed and configured, NMI payments options will be available in the storefront.

## Features & Usage

#### 1. Credit Card Capture

This feature allows customers to complete transactions via NMI’s payment gateway using a credit card. Ensure that the API configuration for live transactions is set up correctly before using this feature.

**How It Works:**
- Customers enter their credit card details in a PCI-compliant form.
- The payment is processed securely via NMI.
- Once the plugin is installed and activated, **NMI Credit Card** will be available as a payment method in the storefront.

**Steps:**
1. Select **"NMI Credit Card"** as the payment method.
2. Click **"Pay."**
3. Enter credit card details into the PCI-compliant NMI form.
4. Submit payment.
![Group 7935](https://github.com/user-attachments/assets/ac621b76-417d-4f1d-a4f1-af286f9cfece)
![Group 7973](https://github.com/user-attachments/assets/3f03e15d-4dcd-4910-b69c-fca94b9e72aa)

#### 2. ACH Capture

ACH Capture (eCheck) enables customers to make payments using their bank account details, offering an alternative to credit card transactions.

**How It Works:**
- ACH transactions transfer funds from the customer's bank account directly.

**Steps:**
1. Select **"NMI ACH (eCheck)"** as the payment method.
2. Click **"Pay."**
3. Enter the required bank account information.
4. Submit payment.

#### 3. Authorize and Capture for Credit Card

This feature allows payment authorization and capture in two stages. The payment is initially authorized, freezing the funds in the customer’s bank account. The admin can then approve or decline the charge, completing or canceling the transaction.

**How It Works:**
- Payment status remains "Authorize" until manually approved.
- Admin can either Capture (charge the customer) or Cancel the transaction.

**Steps:**
1. Enable the **"Authorize and Capture"** feature in plugin settings.
2. New orders will show as **"Authorized."**
3. Navigate to **Admin Panel → Orders**, and change the payment status to **"Paid"** or **"Cancelled."**
![Group 7937](https://github.com/user-attachments/assets/61aa0dde-f67b-4d95-b37e-8a8eb21269dd)
![Group 7939](https://github.com/user-attachments/assets/12cda0d9-6bde-4a18-9f5a-1d975af84f88)


#### 4. COLI (Cancel Order by Line Item)

COLI allows admins to cancel specific items in an order without affecting the entire transaction, providing more flexibility in managing returns. It allows the cancellation of specific line items from an order without canceling the entire order.

**How It Works:**
- Customers can choose which products to cancel.
- The remaining items can still be paid for.

**Steps:**
1. Select the product(s) you want to cancel.
2. Click **"Delete"** to remove the item(s).
3. Save the updated order.
4. Change the payment status from **"Authorized"** to **"Paid."**
![Group 7961](https://github.com/user-attachments/assets/1e4bcafa-5b9b-4402-97dd-d348688bf7af)
![Group 7974](https://github.com/user-attachments/assets/93a16109-3450-4072-99dd-d2b517dcb79e)
![Group 7956](https://github.com/user-attachments/assets/83eef18d-a447-4cbd-ba67-26548c46416e)

_The Coli feature is designed to work exclusively with iPaaS (Integration Platform as a Service) solutions and similar platforms._

#### 4. Refunds (Full & Partial)

Supports full and partial refunds through NMI.

**How It Works:**
- **Full refunds** return the entire payment.
- **Partial refunds** return only a portion of the total amount.

**How to Use (Full Refund):**
1. Navigate to **Orders → Item Section**.
2. Select the product(s) to be refunded.
3. Click **Return Items**.
4. Save the order.
5. Click **Create Refund**.
6. Change status to **"In Progress"**.

**How to Use (Partial Refund):**
1. Navigate to **Orders → Item Section**.
2. Select the product(s) to be refunded.
3. Adjust the refund amount (full or partial).
4. Click **Return Items**.
5. Save the order.
6. Click **Create Refund**.
7. Change status to **"In Progress"**.

##### Full Refund:
![Group 7954 (1)](https://github.com/user-attachments/assets/7708bf75-53c8-4566-b0ac-644baaea57e2)
![Group 7975](https://github.com/user-attachments/assets/b8c6043f-e51d-42e4-9864-85b2db706057)
![Group 7962](https://github.com/user-attachments/assets/7b82abdb-d850-4a22-9d0d-4603ad162025)


##### Partial Refund:
1. Navigate to the order and select the product for return.
2. Specify the quantity of items to be refunded.
3. Return the item(s).
4. Save the order.
5. Create a partial refund.
6. Update the order status to **"In Progress"**.
![Group 7964](https://github.com/user-attachments/assets/92ce3fb0-4d0c-486e-80a6-4477d705e302)
![Group 7965](https://github.com/user-attachments/assets/2ab81360-ed28-4a6f-b525-c2ec8447ace5)
![Group 7966](https://github.com/user-attachments/assets/8353a8a6-1001-40ff-99d3-841134e566cc)

#### 5. Mixed Card

The Mixed Card feature enables customers to purchase both regular products and subscription-based products using a single cart. This functionality integrates standard purchases and subscriptions into the same transaction.

**How It Works:**
- The system differentiates between one-time purchases and subscription-based payments.
- The same credit card can be used for both types of transactions.

**How to Use:**
1. Add regular and subscription products to the cart.
2. Proceed to checkout.
3. Pay with a single credit card.
![Group 7981](https://github.com/user-attachments/assets/58c8c47c-754f-4631-9dd1-8e206f990d8d)

#### 6. Save Card Feature

Allows customers to securely save their card details for future transactions.

**How It Works:**
- A **Vaulted ID** is created to store the customer’s payment details.
- Customers can choose one-click payments for future transactions.

**How to Use:**
1. Select products and proceed to checkout.
2. Fill in payment details.
3. Check the box **"Save my card for future use"**.
4. The saved card will appear as a payment option in future checkouts.

**Managing Saved Cards:**
- **Delete Card**: Removes saved card details from NMI.
- **Add Card**: Allows adding a new card for future transactions.
![Group 7982](https://github.com/user-attachments/assets/489873ba-b8e4-4404-b0cd-445221b3c5a6)
![Group 7970](https://github.com/user-attachments/assets/effc8f7c-7cff-4cf0-9323-49ac475b82b3)
![Group 7968](https://github.com/user-attachments/assets/fa430be0-cb8c-47f5-89e2-f2ff4953531d)
![Group 7969](https://github.com/user-attachments/assets/6052138a-53c2-4078-a875-b8586a2966f5)

_Only registered users can save a card. Guest users do not have the option to save a card._

### Best Practices

#### 1. Configure API Keys Correctly
- Set up **Live** and **Sandbox** API keys before processing transactions.
- Ensure **Signing Key** and **Public Keys** are correct for secure communication.

#### 2. Monitor Transactions
- Regularly check **Admin Panel → Orders** for proper payment processing.
- Use **Authorize and Capture** to control payment approval before capturing funds.

#### 3. Handle Refunds Carefully
- Verify the correct product for **full/partial refunds**.
- Update order status to **"In Progress"** after processing refunds.

#### 4. Utilize Mixed Card Feature
- Allow customers to use one **credit card** for both regular and subscription products.

#### 5. Save Cards Securely
- Enable **Save Card** for faster future transactions.
- Regularly manage and update saved cards.

#### 6. Clear Cache After Changes
- Always clear the Shopware cache after saving settings to ensure updates are applied.

#### 7. Test in Sandbox Mode
- Use **Sandbox API Keys** to test all payment methods before going live.

#### 8. Stay Updated
- Keep the plugin updated to ensure compatibility and security.


### Troubleshooting

#### No Credit Card Payment Option Appearing
- Verify that the **NMI Credit Card** payment method is enabled in the plugin configuration.
- Check if the **API Keys for Live** are correctly set for live transactions.
- Ensure that the plugin is activated and the cache is cleared.

#### ACH Payments Not Processing
- Double-check that the **NMI ACH (eCheck)** payment method is enabled.
- Verify that the customer's bank account details are correct and in the proper format.

#### Authorize and Capture Not Working
- Ensure the **"Authorize and Capture"** option is activated in the plugin settings.
- Check the order status to confirm that it's marked as "Authorized" before trying to capture or cancel.

#### Refunds Not Processing
- Confirm that the correct **order status** is set to "In Progress" after initiating the refund.
- Check if the refunded items are properly selected in the **Orders → Item Section**.

#### Save Card Feature Not Saving
- Make sure the **"Save my card for future use"** checkbox is selected during checkout.
- Verify that the **Vaulted ID** is properly created in the system.
- If customers still can’t save their cards, clear the cache and ensure the system has no conflicts with other payment methods.

#### Mixed Card Transactions Not Working
- Confirm that both regular and subscription products are added to the cart before checking out.
- Make sure the system correctly differentiates between one-time and subscription payments.

### FAQ

#### 1. **How do I configure the API keys?**
- Go to the plugin settings in the **Shopware Admin Panel** and enter the **Live** and **Sandbox** API keys, along with the **Signing Key** and **Public Keys** for secure transactions.

#### 2. **Can I use ACH for payments?**
- Yes, you can enable **NMI ACH (eCheck)** as a payment method for customers to pay using bank account details.

#### 3. **How does the Authorize and Capture feature work?**
- The payment is first authorized and held. Admins can approve or decline the charge before capturing the funds.

#### 4. **Can I cancel specific items in an order?**
- Yes, you can cancel individual line items without affecting the entire order using the **COLI (Cancel Order by Line Item)** feature.

#### 5. **Can I save customers' credit card details?**
- Yes, you can enable the **Save Card** feature to securely store customers' payment details for future transactions.

#### 6. **How do I handle refunds?**
- Full or partial refunds can be processed through the **Orders** section in the Admin Panel. Update the status to **"In Progress"** after processing.

#### 7. **Can customers purchase both regular and subscription products with one card?**
- Yes, the **Mixed Card** feature allows customers to purchase regular products and subscription-based items using a single credit card.

#### 8. **Is it safe to store card information?**
- Yes, customer card information is stored securely with a **Vaulted ID**, ensuring PCI compliance and enabling future one-click payments.

#### 9. **How do I troubleshoot payment issues?**
- Check your API credentials, ensure the plugin is active, and verify payment statuses in the **Admin Panel**. Clear the cache if settings don’t save.

## Wiki Documentation
Read more about the plugin configuration on our [Wiki](https://github.com/solution25com/nmi-payment-shopware-6-solution25/wiki).


