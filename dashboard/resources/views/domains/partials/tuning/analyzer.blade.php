<div class="vs-tuning-card vs-tuning-card-pad vs-tuning-accent" style="--vs-tuning-accent: #34D399">
  <div class="vs-tuning-section-head">
    <div>
      <h3 class="vs-tuning-section-title vs-tone-analysis">Live Domain Analyzer</h3>
      <p class="vs-tuning-helper mt-1">Scan your domain to auto-detect how many Pages + APIs fire per visit.</p>
    </div>
    <button type="button" x-on:click="startAnalysis()" class="vs-tuning-button vs-tuning-button-emerald" x-bind:disabled="isAnalyzing">
      <span x-text="isAnalyzing ? 'Analyzing...' : 'Analyze Domain'"></span>
    </button>
  </div>

  <div class="vs-tuning-grid vs-tuning-two-grid">
    <div class="vs-tuning-panel">
      <label class="vs-tuning-label">Pages Count</label>
      <p class="vs-tuning-helper mt-1">HTML pages loaded per visit (usually 1)</p>
      <div class="vs-tuning-metric" x-text="pagesCount"></div>
    </div>

    <div class="vs-tuning-panel transition-all" x-bind:class="{ 'ring-1 ring-[#FCB900]/40': isEditingApi }">
      <label class="vs-tuning-label">
        <span>API Count</span>
        <span class="vs-tuning-badge">Editable</span>
      </label>
      <p class="vs-tuning-helper mt-1">Background XHR/fetch calls per page. Edit manually if needed.</p>
      <input type="number" x-model.number="apiCount" x-on:focus="isEditingApi = true" x-on:blur="isEditingApi = false" min="0" class="vs-tuning-input text-2xl" placeholder="0">
    </div>
  </div>

  <div x-show="isAnalyzing" style="display: none;" class="absolute inset-0 z-10 bg-[#090E18]/92 backdrop-blur-md flex flex-col items-center justify-center border-t border-[#34D399]/30" x-transition.opacity>
    <div x-show="!iframeLoaded" class="flex flex-col items-center justify-center animate-pulse px-4 text-center">
      <p class="vs-tuning-loading-title mb-2 text-xl">Analyzing domain...</p>
      <p class="vs-tuning-loading-copy">Wait while API calls are detected.</p>
    </div>
    <template x-if="isAnalyzing">
      <iframe x-bind:src="iframeUrl" x-on:load="iframeLoaded = true" class="w-full h-full max-h-[100%] rounded-lg border-2 border-[#34D399]/30 p-1 bg-white" x-bind:class="iframeLoaded ? 'opacity-100' : 'opacity-0 absolute'"></iframe>
    </template>
  </div>
</div>
