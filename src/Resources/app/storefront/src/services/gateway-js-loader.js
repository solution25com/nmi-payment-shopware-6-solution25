// services/gateway-js-loader.js
export default class GatewayJsLoader {
  static loadGatewayJS(gatewayJsUrl) {
    console.log('Loading Gateway.js...');
    const script = document.createElement('script');
    script.src = gatewayJsUrl;
    document.head.appendChild(script);

    script.onload = () => {
      console.log('Gateway.js loaded');
    };

    script.onerror = () => {
      console.error('Failed to load Gateway.js.');
    };
  }

  static createGateway(publicKey) {
    if (typeof Gateway === 'undefined') {
      console.error('Gateway SDK is not loaded');
      return null;
    }
    return Gateway.create(publicKey);
  }
}
