/* Google Analytics 4: Happa public pages (property "Happa", stream "Happa" www.happa.bz). */
(function () {
    'use strict';
    var MEASUREMENT_ID = 'G-DR9RX5FWYK';
    if (window.location.hostname !== 'www.happa.bz') return;
    var path = window.location.pathname;
    // Keep administration and account/authentication pages out of analytics.
    if (/^\/(admin|filemanager)(\/|$)/i.test(path) ||
        /^\/(users|usersettings)\.php$/i.test(path)) return;
    // "Mail Article to a Friend" and user mail forms are visited almost only by bots.
    if (/^\/profiles\.php$/i.test(path)) return;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', MEASUREMENT_ID);
    // Key event: the contact page shows the success notice after a message was sent.
    if (/^\/contact\.php$/i.test(path) &&
        document.querySelector('.happa-contact__notice--success')) {
        window.gtag('event', 'contact_submit');
    }
    var tag = document.createElement('script');
    tag.async = true;
    tag.src = 'https://www.googletagmanager.com/gtag/js?id=' + MEASUREMENT_ID;
    document.head.appendChild(tag);
}());
