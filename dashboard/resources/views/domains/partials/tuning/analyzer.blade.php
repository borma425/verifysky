<div class="es-card p-5 mb-6 border border-emerald-500/30 bg-emerald-900/10 rounded-xl relative overflow-hidden">
  <div class="flex flex-col md:flex-row md:items-center justify-between mb-5 border-b border-emerald-500/20 pb-4">
    <div>
      <h3 class="text-lg font-semibold text-emerald-100">Live Domain Analyzer</h3>
      <p class="text-sm text-emerald-200/80 mt-1">Scan your domain to auto-detect how many Pages + APIs fire per visit.</p>
    </div>
    <button type="button" x-on:click="startAnalysis()" class="es-btn px-5 py-2 mt-3 md:mt-0 bg-emerald-600 hover:bg-emerald-500 border-emerald-500" x-bind:disabled="isAnalyzing">
      <span x-text="isAnalyzing ? 'Analyzing...' : 'Analyze Domain'"></span>
    </button>
  </div>

  <div class="grid gap-4 md:grid-cols-2 mb-4">
    <div class="bg-gray-800/40 border border-sky-500/20 rounded-lg p-4">
      <label class="mb-1 block text-sm font-medium text-sky-100">Pages Count</label>
      <p class="text-xs text-sky-300/60 mb-2">HTML pages loaded per visit (usually 1)</p>
      <div class="text-3xl font-bold text-white mt-1" x-text="pagesCount"></div>
    </div>

    <div class="bg-gray-800/40 border border-sky-500/20 rounded-lg p-4 transition-all" x-bind:class="{ 'ring-1 ring-sky-400 shadow-[0_0_10px_rgba(56,189,248,0.2)]': isEditingApi }">
      <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
        <span>API Count</span>
        <span class="text-[10px] text-sky-400/50 uppercase tracking-widest font-bold">Editable</span>
      </label>
      <p class="text-xs text-sky-300/60 mb-2">Background XHR/fetch calls per page. Edit manually if needed.</p>
      <input type="number" x-model.number="apiCount" x-on:focus="isEditingApi = true" x-on:blur="isEditingApi = false" min="0" class="es-input w-full text-2xl font-bold py-1 px-3 h-auto leading-tight" placeholder="0">
    </div>
  </div>

  <div x-show="isAnalyzing" style="display: none;" class="absolute inset-0 z-10 bg-gray-900/90 backdrop-blur-md flex flex-col items-center justify-center border-t border-emerald-500/50" x-transition.opacity>
    <div x-show="!iframeLoaded" class="flex flex-col items-center justify-center animate-pulse">
      <p class="text-xl font-bold text-white mb-2 tracking-wide">Analyzing domain...</p>
      <p class="text-sm text-emerald-200">Wait while API calls are detected.</p>
    </div>
    <template x-if="isAnalyzing">
      <iframe x-bind:src="iframeUrl" x-on:load="iframeLoaded = true" class="w-full h-full max-h-[100%] rounded-lg border-2 border-emerald-500/30 p-1 bg-white" x-bind:class="iframeLoaded ? 'opacity-100' : 'opacity-0 absolute'"></iframe>
    </template>
  </div>
</div>
