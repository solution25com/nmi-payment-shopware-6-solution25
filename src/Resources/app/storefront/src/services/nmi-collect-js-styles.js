const BRAND = {
    primary:      '#1f4f8a',
    primaryHover: '#1a4275',
    accent:       '#3f8fcd',
    danger:       '#dc2626',
    dangerLight:  '#fef2f2',
    dangerBorder: '#fca5a5',
    success:      '#16a34a',
    successLight: '#f0fdf4',
    successBorder:'#86efac',
    border:       '#d1d5db',
    borderFocus:  '#1f4f8a',
    text:         '#1f2937',
    background:   '#ffffff',
    font:         "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif",
};

export const NMI_CUSTOM_CSS = {
    'color':            BRAND.text,
    'font-family':      BRAND.font,
    'font-size':        '14px',
    'padding':          '10px 14px',
    'border':           `1px solid ${BRAND.border}`,
    'border-radius':    '8px',
    'background-color': BRAND.background,
    'width':            '100%',
    'height':           '100%',
    'box-sizing':       'border-box',
    'transition':       'border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out',
    'line-height':      '1.5',
};

export const NMI_FOCUS_CSS = {
    'border-color': BRAND.borderFocus,
    'box-shadow':   `0 0 0 3px rgba(31, 79, 138, 0.15)`,
    'outline':      '0',
};

export const NMI_INVALID_CSS = {
    'color':            BRAND.danger,
    'border-color':     BRAND.dangerBorder,
    'background-color': BRAND.dangerLight,
};

export const NMI_VALID_CSS = {
    'color':            BRAND.text,
    'border-color':     BRAND.successBorder,
    'background-color': BRAND.successLight,
};

export const COLLECT_JS_INLINE_CC = {
    variant:      'inline',
    paymentType:  'cc',
    googleFont:   'Inter',
    customCss:    NMI_CUSTOM_CSS,
    focusCss:     NMI_FOCUS_CSS,
    invalidCss:   NMI_INVALID_CSS,
    validCss:     NMI_VALID_CSS,
};

export const COLLECT_JS_INLINE_ACH = {
    variant:      'inline',
    paymentType:  'ck',
    googleFont:   'Inter',
    customCss:    NMI_CUSTOM_CSS,
    focusCss:     NMI_FOCUS_CSS,
    invalidCss:   NMI_INVALID_CSS,
    validCss:     NMI_VALID_CSS,
};

export const COLLECT_JS_LIGHTBOX_CC = {
    paymentType:    'cc',
    theme:          'bootstrap',
    googleFont:     'Inter',
    primaryColor:   BRAND.primary,
    secondaryColor: BRAND.accent,
    buttonText:     'Pay Securely',
    customCss:      NMI_CUSTOM_CSS,
    focusCss:       NMI_FOCUS_CSS,
    invalidCss:     NMI_INVALID_CSS,
    validCss:       NMI_VALID_CSS,
};

export const COLLECT_JS_LIGHTBOX_ACH = {
    paymentType:    'ck',
    theme:          'bootstrap',
    googleFont:     'Inter',
    primaryColor:   BRAND.primary,
    secondaryColor: BRAND.accent,
    buttonText:     'Pay Securely',
    customCss:      NMI_CUSTOM_CSS,
    focusCss:       NMI_FOCUS_CSS,
    invalidCss:     NMI_INVALID_CSS,
    validCss:       NMI_VALID_CSS,
};

export const COLLECT_JS_SAVED_CARDS = {
    variant:    'inline',
    googleFont: 'Inter',
    customCss:  { ...NMI_CUSTOM_CSS, 'border-color': BRAND.border },
    focusCss:   NMI_FOCUS_CSS,
    invalidCss: NMI_INVALID_CSS,
    validCss:   NMI_VALID_CSS,
    fields: {
        ccnumber: { selector: '#ccnumber', title: 'Card Number',      placeholder: '•••• •••• •••• ••••' },
        ccexp:    { selector: '#ccexp',    title: 'Expiration Date',   placeholder: 'MM / YY' },
        cvv:      { display: 'show', selector: '#cvv', title: 'Security Code', placeholder: '•••' },
    },
};
