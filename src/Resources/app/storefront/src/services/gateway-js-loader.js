// services/gateway-js-loader.js
export default class GatewayJsLoader {
  static loadGatewayJS(gatewayJsUrl) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = gatewayJsUrl;
      script.async = true;

      script.onload = () => resolve(Gateway);
      script.onerror = () => reject(new Error('Failed to load Gateway.js.'));

      document.head.appendChild(script);
    });
  }

  static createGateway(publicKey) {
    if (typeof Gateway === 'undefined') {
      throw new Error('Gateway SDK is not loaded. Call loadGatewayJS first.');
    }
    return Gateway.create(publicKey);
  }
}
