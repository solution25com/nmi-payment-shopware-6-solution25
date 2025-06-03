export default class CollectJsLoader {
    static loadCollectJS(collectJsUrl, callback, paymentType, options = {}) {
        console.log('Loading CollectJS...');

        return new Promise((resolve, reject) => {
            if (typeof CollectJS === 'undefined') {
                console.log('after start')
                const script = document.createElement('script');
                script.src = collectJsUrl;
                script.setAttribute('data-tokenization-key', 'jygC3z-8XkphM-JEBByn-6JTRdC');
                document.head.appendChild(script);

                script.onload = () => {
                    console.log('CollectJS loaded and configured');
                    CollectJS.configure({
                        paymentType: paymentType,
                        callback,
                        ...options
                    });
                    resolve();
                };

                script.onerror = () => {
                    console.error('Failed to load CollectJS.');
                    reject();
                };
            } else {
                console.warn('CollectJS is already loaded');
                CollectJS.configure({
                    paymentType: paymentType,
                    callback,
                    ...options
                });
                resolve();
            }
        });
    }
}
