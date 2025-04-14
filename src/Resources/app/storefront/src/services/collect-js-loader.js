export default class CollectJsLoader {
    static loadCollectJS(collectJsUrl, callback, paymentType, options = {}) {
        if (typeof CollectJS === 'undefined') {
            const script = document.createElement('script');
            script.src = collectJsUrl;
            script.setAttribute(
                'data-tokenization-key',
                'jygC3z-8XkphM-JEBByn-6JTRdC' // TODO: Do not hardcode key
            );
            document.head.appendChild(script);

            script.onload = () => {
                CollectJS.configure({
                    paymentType,
                    callback,
                    ...options,
                });
            };

            script.onerror = () => {
                console.error('Failed to load CollectJS.');
            };
        } else {
            CollectJS.configure({
                paymentType,
                callback,
                ...options,
            });
        }
    }
}
