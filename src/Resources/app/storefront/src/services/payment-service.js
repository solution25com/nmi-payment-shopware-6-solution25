export default class PaymentService {

  static submitPayment(url, paymentData) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(paymentData),
    })
      .then(async (response) => {
        console.log('response in payment-service before if ok statement', response);
        if (!response.ok) {
          console.log('here after the ok',response)
          const errorData = await response.json().catch(() => null);
          throw new Error(
            errorData?.message || 'Payment submission failed with an unknown error'
          );
        }
        return response.json();
      })
      .catch((error) => {
        console.error('Error during payment submission:', error);
        throw error;
      });
  }

  static fetchCustomerData(url, customerData) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(customerData),
    })
      .then(async (response) => {
        if (!response.ok) {
          const errorData = await response.json().catch(() => null);
          throw new Error(
            errorData?.message || 'Failed to fetch customer data due to an unknown error'
          );
        }
        return response.json();
      })
      .catch((error) => {
        console.error('Error fetching customer data:', error);
        throw error;
      });
  }

  static addBillingToCustomerData(url, customerData) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(customerData),
    })
        .then(async (response) => {
          if (!response.ok) {
            const errorData = await response.json().catch(() => null);
            throw new Error(
                errorData?.message || 'Failed to add customer billing data '
            );
          }
          return response.json();
        })
        .catch((error) => {
          console.error('Error adding customer billing data:', error);
          throw error;
        });
  }
}
