export default class CollectJsLoader {
    static loadCollectJS(collectJsUrl, callback, paymentType, options = {}, onError = () => {} ) {

        let parentCreditCardWrapper = document.getElementById('nmi-credit-card');
        const parentCreditCardWrapperACH = document.getElementById('nmi-ach-echeck');
        if (parentCreditCardWrapper == null){
            parentCreditCardWrapper = parentCreditCardWrapperACH;
        }
        const configs = JSON.parse(parentCreditCardWrapper.getAttribute('data-configs'));

        return new Promise((resolve, reject) => {
            if (typeof CollectJS === 'undefined') {
                const script = document.createElement('script');
                script.src = collectJsUrl;
                script.setAttribute('data-tokenization-key', configs.publicKey);
                document.head.appendChild(script);
                script.onload = () => {
                    try{
                        CollectJS.configure({
                            paymentType: paymentType,
                            callback,
                            ...options
                        });
                        resolve();
                    }
                    catch (err){
                        onError();
                        reject()
                    }

                };

                script.onerror = () => {
                    console.error('Failed to load CollectJS.');
                    onError()
                    reject();
                };
            } else {
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
