(()=>{"use strict";var e={857:e=>{var t=function(e){var t;return!!e&&"object"==typeof e&&"[object RegExp]"!==(t=Object.prototype.toString.call(e))&&"[object Date]"!==t&&e.$$typeof!==r},r="function"==typeof Symbol&&Symbol.for?Symbol.for("react.element"):60103;function o(e,t){return!1!==t.clone&&t.isMergeableObject(e)?s(Array.isArray(e)?[]:{},e,t):e}function n(e,t,r){return e.concat(t).map(function(e){return o(e,r)})}function a(e){return Object.keys(e).concat(Object.getOwnPropertySymbols?Object.getOwnPropertySymbols(e).filter(function(t){return Object.propertyIsEnumerable.call(e,t)}):[])}function i(e,t){try{return t in e}catch(e){return!1}}function s(e,r,l){(l=l||{}).arrayMerge=l.arrayMerge||n,l.isMergeableObject=l.isMergeableObject||t,l.cloneUnlessOtherwiseSpecified=o;var c,d,u=Array.isArray(r);return u!==Array.isArray(e)?o(r,l):u?l.arrayMerge(e,r,l):(d={},(c=l).isMergeableObject(e)&&a(e).forEach(function(t){d[t]=o(e[t],c)}),a(r).forEach(function(t){(!i(e,t)||Object.hasOwnProperty.call(e,t)&&Object.propertyIsEnumerable.call(e,t))&&(i(e,t)&&c.isMergeableObject(r[t])?d[t]=(function(e,t){if(!t.customMerge)return s;var r=t.customMerge(e);return"function"==typeof r?r:s})(t,c)(e[t],r[t],c):d[t]=o(r[t],c))}),d)}s.all=function(e,t){if(!Array.isArray(e))throw Error("first argument should be an array");return e.reduce(function(e,r){return s(e,r,t)},{})},e.exports=s}},t={};function r(o){var n=t[o];if(void 0!==n)return n.exports;var a=t[o]={exports:{}};return e[o](a,a.exports,r),a.exports}(()=>{r.n=e=>{var t=e&&e.__esModule?()=>e.default:()=>e;return r.d(t,{a:t}),t}})(),(()=>{r.d=(e,t)=>{for(var o in t)r.o(t,o)&&!r.o(e,o)&&Object.defineProperty(e,o,{enumerable:!0,get:t[o]})}})(),(()=>{r.o=(e,t)=>Object.prototype.hasOwnProperty.call(e,t)})(),(()=>{var e=r(857),t=r.n(e);class o{static ucFirst(e){return e.charAt(0).toUpperCase()+e.slice(1)}static lcFirst(e){return e.charAt(0).toLowerCase()+e.slice(1)}static toDashCase(e){return e.replace(/([A-Z])/g,"-$1").replace(/^-/,"").toLowerCase()}static toLowerCamelCase(e,t){let r=o.toUpperCamelCase(e,t);return o.lcFirst(r)}static toUpperCamelCase(e,t){return t?e.split(t).map(e=>o.ucFirst(e.toLowerCase())).join(""):o.ucFirst(e.toLowerCase())}static parsePrimitive(e){try{return/^\d+(.|,)\d+$/.test(e)&&(e=e.replace(",",".")),JSON.parse(e)}catch(t){return e.toString()}}}class n{static isNode(e){return"object"==typeof e&&null!==e&&(e===document||e===window||e instanceof Node)}static hasAttribute(e,t){if(!n.isNode(e))throw Error("The element must be a valid HTML Node!");return"function"==typeof e.hasAttribute&&e.hasAttribute(t)}static getAttribute(e,t){let r=!(arguments.length>2)||void 0===arguments[2]||arguments[2];if(r&&!1===n.hasAttribute(e,t))throw Error('The required property "'.concat(t,'" does not exist!'));if("function"!=typeof e.getAttribute){if(r)throw Error("This node doesn't support the getAttribute function!");return}return e.getAttribute(t)}static getDataAttribute(e,t){let r=!(arguments.length>2)||void 0===arguments[2]||arguments[2],a=t.replace(/^data(|-)/,""),i=o.toLowerCamelCase(a,"-");if(!n.isNode(e)){if(r)throw Error("The passed node is not a valid HTML Node!");return}if(void 0===e.dataset){if(r)throw Error("This node doesn't support the dataset attribute!");return}let s=e.dataset[i];if(void 0===s){if(r)throw Error('The required data attribute "'.concat(t,'" does not exist on ').concat(e,"!"));return s}return o.parsePrimitive(s)}static querySelector(e,t){let r=!(arguments.length>2)||void 0===arguments[2]||arguments[2];if(r&&!n.isNode(e))throw Error("The parent node is not a valid HTML Node!");let o=e.querySelector(t)||!1;if(r&&!1===o)throw Error('The required element "'.concat(t,'" does not exist in parent node!'));return o}static querySelectorAll(e,t){let r=!(arguments.length>2)||void 0===arguments[2]||arguments[2];if(r&&!n.isNode(e))throw Error("The parent node is not a valid HTML Node!");let o=e.querySelectorAll(t);if(0===o.length&&(o=!1),r&&!1===o)throw Error('At least one item of "'.concat(t,'" must exist in parent node!'));return o}static getFocusableElements(){let e=arguments.length>0&&void 0!==arguments[0]?arguments[0]:document.body;return e.querySelectorAll('\n            input:not([tabindex^="-"]):not([disabled]):not([type="hidden"]),\n            select:not([tabindex^="-"]):not([disabled]),\n            textarea:not([tabindex^="-"]):not([disabled]),\n            button:not([tabindex^="-"]):not([disabled]),\n            a[href]:not([tabindex^="-"]):not([disabled]),\n            [tabindex]:not([tabindex^="-"]):not([disabled])\n        ')}static getFirstFocusableElement(){let e=arguments.length>0&&void 0!==arguments[0]?arguments[0]:document.body;return this.getFocusableElements(e)[0]}static getLastFocusableElement(){let e=arguments.length>0&&void 0!==arguments[0]?arguments[0]:document,t=this.getFocusableElements(e);return t[t.length-1]}}class a{publish(e){let t=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{},r=arguments.length>2&&void 0!==arguments[2]&&arguments[2],o=new CustomEvent(e,{detail:t,cancelable:r});return this.el.dispatchEvent(o),o}subscribe(e,t){let r=arguments.length>2&&void 0!==arguments[2]?arguments[2]:{},o=this,n=e.split("."),a=r.scope?t.bind(r.scope):t;if(r.once&&!0===r.once){let t=a;a=function(r){o.unsubscribe(e),t(r)}}return this.el.addEventListener(n[0],a),this.listeners.push({splitEventName:n,opts:r,cb:a}),!0}unsubscribe(e){let t=e.split(".");return this.listeners=this.listeners.reduce((e,r)=>([...r.splitEventName].sort().toString()===t.sort().toString()?this.el.removeEventListener(r.splitEventName[0],r.cb):e.push(r),e),[]),!0}reset(){return this.listeners.forEach(e=>{this.el.removeEventListener(e.splitEventName[0],e.cb)}),this.listeners=[],!0}get el(){return this._el}set el(e){this._el=e}get listeners(){return this._listeners}set listeners(e){this._listeners=e}constructor(e=document){this._el=e,e.$emitter=this,this._listeners=[]}}class i{init(){throw Error('The "init" method for the plugin "'.concat(this._pluginName,'" is not defined.'))}update(){}_init(){this._initialized||(this.init(),this._initialized=!0)}_update(){this._initialized&&this.update()}_mergeOptions(e){let r=o.toDashCase(this._pluginName),a=n.getDataAttribute(this.el,"data-".concat(r,"-config"),!1),i=n.getAttribute(this.el,"data-".concat(r,"-options"),!1),s=[this.constructor.options,this.options,e];a&&s.push(window.PluginConfigManager.get(this._pluginName,a));try{i&&s.push(JSON.parse(i))}catch(e){throw console.error(this.el),Error('The data attribute "data-'.concat(r,'-options" could not be parsed to json: ').concat(e.message))}return t().all(s.filter(e=>e instanceof Object&&!(e instanceof Array)).map(e=>e||{}))}_registerInstance(){window.PluginManager.getPluginInstancesFromElement(this.el).set(this._pluginName,this),window.PluginManager.getPlugin(this._pluginName,!1).get("instances").push(this)}_getPluginName(e){return e||(e=this.constructor.name),e}constructor(e,t={},r=!1){if(!n.isNode(e))throw Error("There is no valid element given.");this.el=e,this.$emitter=new a(this.el),this._pluginName=this._getPluginName(r),this.options=this._mergeOptions(t),this._initialized=!1,this._registerInstance(),this._init()}}class s{static loadCollectJS(e,t,r){let o=arguments.length>3&&void 0!==arguments[3]?arguments[3]:{};if(console.log("Loading CollectJS..."),"undefined"==typeof CollectJS){console.log("after start");let n=document.createElement("script");n.src=e,n.setAttribute("data-tokenization-key","jygC3z-8XkphM-JEBByn-6JTRdC"),document.head.appendChild(n),n.onload=()=>{console.log("CollectJS loaded and configured"),CollectJS.configure({paymentType:r,callback:t,...o})},n.onerror=()=>{console.error("Failed to load CollectJS.")}}else console.warn("CollectJS is already loaded"),CollectJS.configure({paymentType:r,callback:t,...o})}}class l{static loadGatewayJS(e){console.log("Loading Gateway.js...");let t=document.createElement("script");t.src=e,document.head.appendChild(t),t.onload=()=>{console.log("Gateway.js loaded")},t.onerror=()=>{console.error("Failed to load Gateway.js.")}}static createGateway(e){return"undefined"==typeof Gateway?(console.error("Gateway SDK is not loaded"),null):Gateway.create(e)}}class c{static submitPayment(e,t){return fetch(e,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)}).then(async e=>{if(console.log("response in payment-service before if ok statement",e),!e.ok){console.log("here after the ok",e);let t=await e.json().catch(()=>null);throw Error((null==t?void 0:t.message)||"Payment submission failed with an unknown error")}return e.json()}).catch(e=>{throw console.error("Error during payment submission:",e),e})}static fetchCustomerData(e,t){return fetch(e,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)}).then(async e=>{if(!e.ok){let t=await e.json().catch(()=>null);throw Error((null==t?void 0:t.message)||"Failed to fetch customer data due to an unknown error")}return e.json()}).catch(e=>{throw console.error("Error fetching customer data:",e),e})}static addBillingToCustomerData(e,t){return fetch(e,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)}).then(async e=>{if(!e.ok){let t=await e.json().catch(()=>null);throw Error((null==t?void 0:t.message)||"Failed to add customer billing data ")}return e.json()}).catch(e=>{throw console.error("Error adding customer billing data:",e),e})}}class d extends i{init(){this._registerElements(),console.log(this.dropdownCards),this._registerEvents(),this.isSavedCardBackend&&(this.getVaultedCustomerData(),this.fillDropdown())}_registerElements(){this.parentCreditCardWrapper=document.getElementById(this.options.parentCreditCardWrapperId),this.vaultedId=this.parentCreditCardWrapper.getAttribute("data-vaulted-customer-id"),this.billingId=this.parentCreditCardWrapper.getAttribute("data-billing-customer-id"),this.isSavedCardBackend=this.parentCreditCardWrapper.getAttribute("data-saved-card"),this.currency=this.parentCreditCardWrapper.getAttribute("data-shop-currency"),this.amount=this.parentCreditCardWrapper.getAttribute("data-amount"),this.threeDSConfig=this.parentCreditCardWrapper.getAttribute("data-threeDSConfig"),this.dropdownCards=this.parentCreditCardWrapper.getAttribute("data-dropdown-cards"),this.deleteDataBtn=document.getElementById("delete-vaulted-customer-data"),this.addMoreCards=document.getElementById("add-another-vaulted-card"),this.loader=document.getElementById("nmiLoader"),this.cardHolderFirstName=document.getElementById("card-holder-first-name"),this.cardHolderLastName=document.getElementById("card-holder-last-name"),this.confirmOrderForm=document.forms[this.options.confirmFormId]}_registerEvents(){this.confirmOrderForm.addEventListener("submit",this._onPayButtonClick.bind(this)),this.deleteDataBtn&&this.deleteDataBtn.addEventListener("click",this._onDeleteButtonClick.bind(this)),this.addMoreCards&&this.addMoreCards.addEventListener("click",this._onAddCardButtonClick.bind(this))}async _onPayButtonClick(e){e.preventDefault(),s.loadCollectJS(this.options.collectJsUrl,this.submitPayment.bind(this),this.options.paymentType,{}),this.confirmOrderForm.checkValidity()&&(this.isSavedCardBackend?this.submitVaultedPayment():"undefined"!=typeof CollectJS&&CollectJS.startPaymentRequest())}_onDeleteButtonClick(e){e.preventDefault(),this._showLoading(!0),this.deleteVaultedCustomerData()}async _onAddCardButtonClick(e){e.preventDefault(),s.loadCollectJS(this.options.collectJsUrl,this.addBillingToCustomer.bind(this),this.options.paymentType,{theme:"bootstrap",primaryColor:"#ff288d",secondaryColor:"#3e79db",buttonText:"Add New Credit Card"}),this.confirmOrderForm.checkValidity()&&"undefined"!=typeof CollectJS&&CollectJS.startPaymentRequest()}_showLoading(e){let t=document.getElementById("nmiLoader");t&&(t.style.display=e?"inline-block":"none")}submitPayment(e){if(console.log("Processing payment with response:",e),!e.token){console.error("Tokenization failed:",e.error),alert("Payment tokenization failed. Please try again.");return}this.isSavedCardBackend?(this.submitVaultedPayment(e),this._showLoading(!1)):this.submitNormalPayment(e)}addBillingToCustomer(e){if(console.log("Processing payment with response:",e),e.token)this.addCards(e);else{console.error("Tokenization failed:",e.error),alert("Payment tokenization failed. Please try again.");return}}addCards(e){let t={token:e.token,ccnumber:e.card.number,ccexp:e.card.exp,card_type:e.card.type,vaulted_customer_id:this.vaultedId,first_name:"Test",last_name:"Test"};this.submitCard(this.options.paymentUrls.addCard,t)}deleteVaultedCustomerData(e){console.log("here in delete data for vaulted");let t={customer_vault_id:this.vaultedId},r=this.options.paymentUrls.deleteVaultedCustomerData;c.fetchCustomerData(r,t).then(e=>{console.log("Vaulted Customer Data:",e),this._showLoading(!1),window.location.reload()}).catch(e=>{alert("Error deleting vaulted customer data: "+e),console.error("Error deleting vaulted customer data:",e),this._showLoading(!1)})}getVaultedCustomerData(e){console.log("here in get data for vaulted");let t={customer_vault_id:this.vaultedId},r=this.options.paymentUrls.getVaultedData;this._showLoading(!0),c.fetchCustomerData(r,t).then(e=>{console.log("Vaulted Customer Data:",e),this.displayVaultedCustomerData(e),this._showLoading(!1)}).catch(e=>{alert("Error fetching vaulted customer data: "+e),console.error("Error fetching vaulted customer data:",e),this._showLoading(!1)})}fillDropdown(){let e=JSON.parse(this.dropdownCards);var t=document.getElementById("cardSelect");if(e.length>0)e.forEach(function(e){var r=document.createElement("option");r.value=e.vaultedCustomerId,r.value=e.billingId,r.textContent="".concat(e.firstName," ****").concat(e.lastDigits.slice(-4)),e.isDefault&&(r.selected=!0),t.appendChild(r)});else{var r=document.createElement("option");r.value="",r.textContent="No saved cards available",t.appendChild(r)}}displayVaultedCustomerData(e){e&&e.first_name&&e.last_name&&e.cc_number&&e.cc_type?(document.getElementById("vaulted-first-name").innerText=e.first_name,document.getElementById("vaulted-last-name").innerText=e.last_name,document.getElementById("vaulted-last-four-digits").innerText=e.cc_number,document.getElementById("vaulted-card-type").innerText=e.cc_type):console.error("Vaulted customer data is incomplete or missing.")}submitNormalPayment(e){let t,r;let o=this.threeDSConfig;console.log("normal payment response: ",e);let n={token:e.token,amount:this.amount,first_name:document.querySelector('input[name="fname"]').value,last_name:document.querySelector('input[name="lname"]').value,address1:document.querySelector('input[name="address1"]').value,city:document.querySelector('input[name="city"]').value,zip:document.querySelector('input[name="zip"]').value,ccnumber:e.card.number,ccexp:e.card.exp,card_type:e.card.type,customer_vault:document.querySelector("#saveCardCheckbox")&&document.querySelector("#saveCardCheckbox").checked?"add_customer":null,saveCard:!!document.querySelector("#saveCardCheckbox")&&document.querySelector("#saveCardCheckbox").checked};if(console.log("paymentDAtaNormal",n),o){let o=document.createElement("script");o.src=this.options.gatewayJsUrl,document.head.appendChild(o),o.onload=()=>{if(console.log("Gateway.js loaded for 3D Secure"),t=l.createGateway("checkout_public_5633yXujrK9K6Cf2NTVcQhv635WSpZNs")){r=t.get3DSecure(),console.log(t),n.cavv=e.cavv,n.xid=e.xid,n.eci=e.eci,n.cardHolderAuth=e.cardHolderAuth,n.threeDsVersion=e.threeDsVersion,n.directoryServerId=e.directoryServerId,n.cardHolderInfo=e.cardHolderInfo;let o=r.createUI(n);o.start("body"),console.log("we are 3d body"),o.on("challenge",function(e){console.log("Challenged")}),o.on("failure",function(e){console.log("failure"),console.log(e)}),t.on("error",function(e){console.error(e)}),this.submitToPaymentService(this.options.paymentUrls.creditCard,n)}},o.onerror=()=>{console.error("Failed to load Gateway.js.")}}else console.log("3D Secure not activated, proceeding with normal payment."),this.submitToPaymentService(this.options.paymentUrls.creditCard,n)}submitVaultedPayment(e){console.log("Submitting vaulted payment"),this._showLoading(!0);let t=document.getElementById("cardSelect");console.log("selectCard:",t);let r=t?t.value:null;console.log("selectedId",r);let o={amount:this.amount,customer_vault_id:this.vaultedId,billing_id:null!=r?r:null},n=this.options.paymentUrls.vaulted;this.submitToPaymentService(n,o,!0)}displayErrors(e){let t=document.getElementById("error-message"),r=t.querySelector(".error-alert");r.innerHTML="",e.length>0?(r.textContent=e.join(" "),t.classList.remove("d-none"),t.classList.add("d-block")):(t.classList.add("d-none"),t.classList.remove("d-block"))}submitToPaymentService(e,t){arguments.length>2&&void 0!==arguments[2]&&arguments[2],console.log("Submitting payment to service..."),c.submitPayment(e,t).then(e=>{if(console.log("Server response- Js:",e),e.success){let t=e.responses.payment.transaction_id,r=e.responses.payment.isSubscriptionCart;console.log("transactionID:",t),t&&(document.getElementById("nmi-transaction-id").value=null!=t?t:null,document.getElementById("nmi-is-subscription").value=null!=r?r:null),document.getElementById("confirmOrderForm").submit()}else{let t=e.errors||[e.message||"An unknown error occurred"];this.displayErrors(t)}}).catch(e=>{console.error("Error submitting payment:",e),this.displayErrors([e.message||"Unexpected error occurred. Please try again later."])})}submitCard(e,t){arguments.length>2&&void 0!==arguments[2]&&arguments[2],c.addBillingToCustomerData(e,t).then(e=>{if(console.log("Server response:",e),e.success)alert("Payment success: ".concat(e.message));else{let t=e.errors||[e.message||"An unknown error occurred"];this.displayErrors(t)}}).catch(e=>{console.error("Error submitting payment:",e),this.displayErrors([e.message||"Unexpected error occurred. Please try again later."])})}}d.options={confirmFormId:"confirmOrderForm",formSelector:".lightbox-container",paymentUrls:{creditCard:"/nmi-payment-credit-card",vaulted:"/nmi-payment-vaulted-customer",getVaultedData:"/nmi-payment-get-vaulted-customer",deleteVaultedCustomerData:"/nmi-payment-delete-vaulted-customer",addCard:"/nmi-add-card"},collectJsUrl:"https://secure.nmi.com/token/Collect.js",gatewayJsUrl:"https://secure.nmi.com/js/v1/Gateway.js",paymentType:"cc",parentCreditCardWrapperId:"nmi-credit-card"};class u extends i{init(){this._registerElements(),this._registerEvents(),console.log(this.amount)}_registerElements(){this.parentCreditCardWrapper=document.getElementById(this.options.parentCreditCardWrapperId),this.amount=this.parentCreditCardWrapper.getAttribute("data-amount"),this.confirmOrderForm=document.forms[this.options.confirmFormId]}_registerEvents(){this.confirmOrderForm.addEventListener("submit",this._onPayButtonClick.bind(this))}async _onPayButtonClick(e){e.preventDefault(),s.loadCollectJS(this.options.collectJsUrl,this.submitPayment.bind(this),this.options.paymentType),this.confirmOrderForm.checkValidity()&&("undefined"!=typeof CollectJS?CollectJS.startPaymentRequest():console.error("CollectJS is not available"))}submitPayment(e){if(console.log("Processing payment with response:",e),!e||!e.token){console.error("Tokenization failed:",e?e.error:"No response"),alert("Payment tokenization failed. Please try again.");return}this.submitaCheck(e)}submitaCheck(e){if(!e||!e.token){console.error("Tokenization failed:",e?e.error:"No response"),alert("Payment tokenization failed. Please try again.");return}console.log("ACH response:",e);let t={token:e.token,amount:this.amount,checkname:e.check.name,checkaba:e.check.aba,checkaccount:e.check.account};console.log("Payment data:",t),this.submitToPaymentService(this.options.paymentUrl,t)}submitToPaymentService(e,t){console.log("Submitting payment to service..."),console.log(e,t),c.submitPayment(e,t).then(e=>{console.log("Server response:",e),e.success?(document.getElementById("nmi-transaction-id").value=JSON.parse(e.transaction_id),alert("Payment success: ".concat(e.message)),document.getElementById("confirmOrderForm").submit()):alert("Payment failed: ".concat(e.message))}).catch(e=>{alert("Error submitting payment: "+e)})}}u.options={confirmFormId:"confirmOrderForm",paymentUrl:"/nmi-payment-ach-e-check",collectJsUrl:"https://secure.nmi.com/token/Collect.js",paymentType:"ck",parentCreditCardWrapperId:"nmi-ach-echeck"};let m=window.PluginManager;m.register("NmiCreditCardPlugin",d,"[nmi-payment-credit-card-plugin]"),m.register("NmiAchEcheckPlugin",u,"[nmi-payment-ach-eCheck-plugin]")})()})();