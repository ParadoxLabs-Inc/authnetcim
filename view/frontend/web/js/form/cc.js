/*jshint jquery:true*/
define([
    "jquery",
    "tokenbaseFormCc"
], function($, tokenbaseFormCc) {
    "use strict";

    $.widget('mage.authnetcimFormCc', $.extend(true, tokenbaseFormCc, {
        options: {
            code: "authnetcim"
        }
    }));

    return $.mage.authnetcimFormCc;
});
