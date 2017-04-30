Config = require './Config'

module.exports =

##*
# Config that retrieves its settings from Atom's config.
##
class AtomConfig extends Config
    ###*
     * The name of the package to use when searching for settings.
    ###
    packageName: null

    ###*
     * @var {Array}
    ###
    configurableProperties: null

    ###*
     * @inheritdoc
    ###
    constructor: (@packageName) ->
        @configurableProperties = [
            'phpCommand'
            'indexContinuously'
            'additionalIndexingDelay'
            'memoryLimit'
            'insertNewlinesForUseStatements'
            'enableTooltips'
            'enableSignatureHelp'
            'enableLinting'
            'showUnknownClasses'
            'showUnknownMembers'
            'showUnknownGlobalFunctions'
            'showUnknownGlobalConstants'
            'showUnusedUseStatements'
            'showMissingDocs'
            'validateDocblockCorrectness'
        ]

        super()

        @attachListeners()

    ###*
     * @inheritdoc
    ###
    load: () ->
        @set('packagePath', atom.packages.resolvePackagePath("#{@packageName}"))

        for property in @configurableProperties
            @set(property, atom.config.get("#{@packageName}.#{property}"))

    ###*
     * Attaches listeners to listen to Atom configuration changes.
    ###
    attachListeners: () ->
        for property in @configurableProperties
            # Hmmm, I thought CoffeeScript automatically solved these variable copy bugs with function creation in
            # loops...
            callback = ((propertyCopy, data) ->
                @set(propertyCopy, data.newValue)
            ).bind(this, property)

            atom.config.onDidChange("#{@packageName}.#{property}", callback)
