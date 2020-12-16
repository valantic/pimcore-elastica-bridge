pimcore.registerNS("pimcore.plugin.ValanticElasticaBridgeBundle");

pimcore.plugin.ValanticElasticaBridgeBundle = Class.create(pimcore.plugin.admin, {
    getClassName: function () {
        return "pimcore.plugin.ValanticElasticaBridgeBundle";
    },

    initialize: function () {
        pimcore.plugin.broker.registerPlugin(this);
    },

    pimcoreReady: function (params, broker) {
        // alert("ValanticElasticaBridgeBundle ready!");
    }
});

var ValanticElasticaBridgeBundlePlugin = new pimcore.plugin.ValanticElasticaBridgeBundle();
