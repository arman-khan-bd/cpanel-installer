<?php
/**
 * Nexus cPanel Web Setup Wizard
 *
 * Shadcn UI inspired, reactive zero-SSH setup wizard.
 *
 * @var array $prerequisites
 * @var string $csrfToken
 * @var string $detectedUrl
 */
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus Setup Wizard &bull; cPanel Zero-SSH Installer</title>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        background: '#030712',
                        foreground: '#f9fafb',
                        card: '#111827',
                        'card-foreground': '#f9fafb',
                        primary: '#3b82f6',
                        'primary-foreground': '#ffffff',
                        secondary: '#1f2937',
                        muted: '#1e293b',
                        'muted-foreground': '#94a3b8',
                        border: '#1f2937',
                        input: '#1f2937',
                        ring: '#3b82f6',
                        success: '#10b981',
                        destructive: '#ef4444',
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js Core -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        [x-cloak] { display: none !important; }
        * { box-sizing: border-box; }
        body {
            background-color: #030712;
            color: #f9fafb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-blue-600 selection:text-white">

    <div x-data="installerWizard()" x-init="init()" class="max-w-3xl mx-auto w-full px-6 py-12 flex-1 flex flex-col justify-center">

        <!-- Header Brand -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-blue-600/10 border border-blue-500/20 text-blue-400 mb-4 shadow-lg shadow-blue-500/5">
                <i data-lucide="shield-check" class="w-7 h-7"></i>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center justify-center gap-2">
                next<span class="text-blue-500">php</span>
                <span class="text-xs px-2 py-0.5 rounded-full bg-blue-500/10 border border-blue-500/30 text-blue-400 font-semibold tracking-wide">cPanel Installer</span>
            </h1>
            <p class="text-sm text-slate-400 mt-1">Zero-SSH graphical environment configuration &amp; fortress setup.</p>
        </div>

        <!-- Stepper Indicator -->
        <div class="mb-8" x-show="step < 4">
            <div class="flex items-center justify-between relative">
                <div class="absolute left-0 top-1/2 -translate-y-1/2 h-0.5 bg-slate-800 w-full z-0"></div>
                <div class="absolute left-0 top-1/2 -translate-y-1/2 h-0.5 bg-blue-600 transition-all duration-300 z-0"
                     :style="'width: ' + ((step - 1) / 2 * 100) + '%'"></div>

                <!-- Step 1 Indicator -->
                <div class="relative z-10 flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs transition-all border"
                         :class="step >= 1 ? 'bg-blue-600 text-white border-blue-500 shadow-lg shadow-blue-600/30' : 'bg-slate-900 text-slate-500 border-slate-800'">
                        <span x-show="step > 1"><i data-lucide="check" class="w-4 h-4"></i></span>
                        <span x-show="step <= 1">1</span>
                    </div>
                    <span class="text-[11px] font-medium mt-2" :class="step >= 1 ? 'text-blue-400 font-semibold' : 'text-slate-500'">System Check</span>
                </div>

                <!-- Step 2 Indicator -->
                <div class="relative z-10 flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs transition-all border"
                         :class="step >= 2 ? 'bg-blue-600 text-white border-blue-500 shadow-lg shadow-blue-600/30' : 'bg-slate-900 text-slate-500 border-slate-800'">
                        <span x-show="step > 2"><i data-lucide="check" class="w-4 h-4"></i></span>
                        <span x-show="step <= 2">2</span>
                    </div>
                    <span class="text-[11px] font-medium mt-2" :class="step >= 2 ? 'text-blue-400 font-semibold' : 'text-slate-500'">Database</span>
                </div>

                <!-- Step 3 Indicator -->
                <div class="relative z-10 flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs transition-all border"
                         :class="step >= 3 ? 'bg-blue-600 text-white border-blue-500 shadow-lg shadow-blue-600/30' : 'bg-slate-900 text-slate-500 border-slate-800'">
                        <span>3</span>
                    </div>
                    <span class="text-[11px] font-medium mt-2" :class="step >= 3 ? 'text-blue-400 font-semibold' : 'text-slate-500'">Admin &amp; App</span>
                </div>
            </div>
        </div>

        <!-- Main Card Container -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-2xl backdrop-blur-xl relative">

            <!-- STEP 1: Pre-requisites & System Health Check -->
            <div x-show="step === 1" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2">
                <div class="border-b border-slate-800 pb-4 mb-6">
                    <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="activity" class="w-5 h-5 text-blue-400"></i>
                        System Health &amp; Environment Pre-requisites
                    </h2>
                    <p class="text-xs text-slate-400 mt-1">Verifying PHP runtime, essential extensions, and storage permissions.</p>
                </div>

                <div class="space-y-4">
                    <!-- PHP Version -->
                    <div class="flex items-center justify-between p-3.5 rounded-xl bg-slate-950/60 border border-slate-800/80">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center font-semibold text-xs"
                                 :class="prereqs.php.pass ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'">
                                <i :data-lucide="prereqs.php.pass ? 'check' : 'x'" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <div class="text-sm font-medium text-white">PHP Version &ge; 8.2</div>
                                <div class="text-xs text-slate-400">Current version: <span class="font-mono text-slate-300" x-text="prereqs.php.current"></span></div>
                            </div>
                        </div>
                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold border"
                              :class="prereqs.php.pass ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' : 'bg-red-500/10 text-red-400 border-red-500/30'"
                              x-text="prereqs.php.pass ? 'Pass' : 'Upgrade Required'"></span>
                    </div>

                    <!-- Extensions List -->
                    <div class="p-4 rounded-xl bg-slate-950/60 border border-slate-800/80">
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Required PHP Extensions</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <template x-for="(ext, key) in prereqs.extensions" :key="key">
                                <div class="flex items-center justify-between p-2.5 rounded-lg bg-slate-900/60 border border-slate-800/60 text-xs">
                                    <div class="flex items-center gap-2">
                                        <i :data-lucide="ext.pass ? 'check-circle-2' : (ext.required ? 'x-circle' : 'alert-circle')"
                                           class="w-4 h-4 shrink-0"
                                           :class="ext.pass ? 'text-emerald-400' : (ext.required ? 'text-red-400' : 'text-amber-400')"></i>
                                        <span class="font-medium text-slate-200" x-text="ext.name"></span>
                                    </div>
                                    <span class="text-[10px] px-2 py-0.5 rounded font-mono"
                                          :class="ext.pass ? 'text-emerald-400 bg-emerald-500/10' : (ext.required ? 'text-red-400 bg-red-500/10' : 'text-amber-400 bg-amber-500/10')"
                                          x-text="ext.pass ? 'Loaded' : (ext.required ? 'Missing' : 'Optional')"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Write Permissions -->
                    <div class="p-4 rounded-xl bg-slate-950/60 border border-slate-800/80">
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">File System Permissions</div>
                        <div class="space-y-2">
                            <template x-for="(perm, key) in prereqs.permissions" :key="key">
                                <div class="flex items-center justify-between p-2.5 rounded-lg bg-slate-900/60 border border-slate-800/60 text-xs">
                                    <div class="flex items-center gap-2">
                                        <i :data-lucide="perm.writable ? 'check-circle-2' : 'x-circle'"
                                           class="w-4 h-4 shrink-0"
                                           :class="perm.writable ? 'text-emerald-400' : 'text-red-400'"></i>
                                        <span class="font-mono text-slate-300" x-text="key.toUpperCase() + ' (' + perm.path + ')'"></span>
                                    </div>
                                    <span class="text-[10px] px-2 py-0.5 rounded font-semibold"
                                          :class="perm.writable ? 'text-emerald-400 bg-emerald-500/10 border border-emerald-500/20' : 'text-red-400 bg-red-500/10 border border-red-500/20'"
                                          x-text="perm.writable ? 'Writable' : 'Read-Only'"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Blocking Errors Notice -->
                    <div x-show="!prereqs.can_install" class="p-4 rounded-xl bg-red-950/40 border border-red-500/30 text-red-200 text-xs space-y-1.5">
                        <div class="font-bold flex items-center gap-1.5 text-red-400">
                            <i data-lucide="alert-octagon" class="w-4 h-4"></i>
                            Action Required in cPanel
                        </div>
                        <p class="text-slate-300">Some critical requirements are not met. Open your cPanel account &rarr; <strong>Select PHP Version</strong> &rarr; switch to PHP 8.2+ and enable the missing extensions.</p>
                    </div>
                </div>

                <div class="mt-8 flex justify-end">
                    <button type="button"
                            @click="step = 2"
                            :disabled="!prereqs.can_install"
                            class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 disabled:opacity-40 disabled:pointer-events-none text-white text-sm font-semibold transition-all shadow-lg shadow-blue-600/20">
                        Continue to Database
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <!-- STEP 2: Database Credentials -->
            <div x-show="step === 2" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2">
                <div class="border-b border-slate-800 pb-4 mb-6">
                    <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="database" class="w-5 h-5 text-blue-400"></i>
                        MySQL / MariaDB Database Connection
                    </h2>
                    <p class="text-xs text-slate-400 mt-1">Enter your cPanel MySQL database credentials created in MySQL Databases.</p>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Database Host</label>
                            <input type="text" x-model="form.db_host" placeholder="127.0.0.1 or localhost"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Port</label>
                            <input type="number" x-model="form.db_port" placeholder="3306"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Database Name</label>
                        <input type="text" x-model="form.db_name" placeholder="cpaneluser_nexusdb"
                               class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        <p class="text-[11px] text-slate-500 mt-1">On cPanel, database names are prefixed with your cPanel username.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Database Username</label>
                            <input type="text" x-model="form.db_user" placeholder="cpaneluser_dbuser"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Database Password</label>
                            <input type="password" x-model="form.db_pass" placeholder="••••••••••••••••"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        </div>
                    </div>

                    <!-- Test Connection Button & Status -->
                    <div class="pt-2">
                        <div class="flex items-center gap-3">
                            <button type="button"
                                    @click="testConnection()"
                                    :disabled="testingDb || !form.db_name || !form.db_user"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:pointer-events-none text-slate-200 text-xs font-semibold border border-slate-700 transition-colors">
                                <svg x-show="testingDb" class="animate-spin h-3.5 w-3.5 text-current" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <i x-show="!testingDb" data-lucide="plug-zap" class="w-3.5 h-3.5"></i>
                                <span x-text="testingDb ? 'Probing Connection...' : 'Test Connection'"></span>
                            </button>

                            <div x-show="dbStatus !== null"
                                 class="text-xs px-3 py-1.5 rounded-lg border flex items-center gap-1.5 transition-all"
                                 :class="dbStatus === true ? 'bg-emerald-950/80 border-emerald-500/40 text-emerald-300' : 'bg-red-950/80 border-red-500/40 text-red-300'">
                                <i :data-lucide="dbStatus === true ? 'check-circle' : 'alert-circle'" class="w-3.5 h-3.5 shrink-0"></i>
                                <span x-text="dbMessage"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex justify-between items-center">
                    <button type="button" @click="step = 1" class="text-xs text-slate-400 hover:text-white flex items-center gap-1 transition-colors">
                        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                        Back
                    </button>
                    <button type="button"
                            @click="step = 3"
                            :disabled="dbStatus !== true"
                            class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 disabled:opacity-40 disabled:pointer-events-none text-white text-sm font-semibold transition-all shadow-lg shadow-blue-600/20">
                        Continue to Admin Setup
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <!-- STEP 3: Admin & Application Configuration -->
            <div x-show="step === 3" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2">
                <div class="border-b border-slate-800 pb-4 mb-6">
                    <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="user-check" class="w-5 h-5 text-blue-400"></i>
                        Administrator Account &amp; App Profile
                    </h2>
                    <p class="text-xs text-slate-400 mt-1">Setup master administrator credentials and public application URL.</p>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Application Name</label>
                            <input type="text" x-model="form.app_name" placeholder="My Nexus Application"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Application URL</label>
                            <input type="url" x-model="form.app_url" placeholder="https://example.com"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Administrator Name</label>
                            <input type="text" x-model="form.admin_name" placeholder="Master Admin"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Administrator Email</label>
                            <input type="email" x-model="form.admin_email" placeholder="admin@example.com"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Administrator Password</label>
                            <input type="password" x-model="form.admin_password" placeholder="At least 8 characters"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Confirm Password</label>
                            <input type="password" x-model="form.admin_password_confirm" placeholder="Repeat password"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-sm text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-mono transition-colors">
                        </div>
                    </div>

                    <!-- Password Match Verification Notice -->
                    <div x-show="form.admin_password && form.admin_password_confirm && form.admin_password !== form.admin_password_confirm"
                         class="text-xs text-red-400 flex items-center gap-1.5">
                        <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                        Passwords do not match.
                    </div>

                    <!-- Error Banner -->
                    <div x-show="errorMessage" class="p-3.5 rounded-xl bg-red-950/80 border border-red-500/40 text-red-300 text-xs flex items-center gap-2">
                        <i data-lucide="alert-octagon" class="w-4 h-4 shrink-0"></i>
                        <span x-text="errorMessage"></span>
                    </div>
                </div>

                <div class="mt-8 flex justify-between items-center">
                    <button type="button" @click="step = 2" class="text-xs text-slate-400 hover:text-white flex items-center gap-1 transition-colors">
                        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                        Back
                    </button>
                    <button type="button"
                            @click="executeInstallation()"
                            :disabled="installing || !canSubmitAdmin()"
                            class="inline-flex items-center gap-2 px-7 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 disabled:pointer-events-none text-white text-sm font-semibold transition-all shadow-lg shadow-emerald-600/20">
                        <svg x-show="installing" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <i x-show="!installing" data-lucide="sparkles" class="w-4 h-4"></i>
                        <span x-text="installing ? 'Fortifying &amp; Installing...' : 'Complete Installation'"></span>
                    </button>
                </div>
            </div>

            <!-- STEP 4: Success & Lock Screen -->
            <div x-show="step === 4" x-cloak class="text-center py-6">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 mb-4 shadow-xl shadow-emerald-500/10">
                    <i data-lucide="check-circle-2" class="w-8 h-8"></i>
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">Installation Complete!</h2>
                <p class="text-sm text-slate-400 max-w-md mx-auto mb-4">
                    Nexus has been installed successfully. Your database schema is provisioned, 256-bit Libsodium encryption key generated, and the security lockfile is armed.
                </p>

                <p class="text-xs text-blue-400 font-semibold mb-6 flex items-center justify-center gap-1.5">
                    <svg class="animate-spin h-3.5 w-3.5 text-blue-400" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    Handshake complete. Redirecting to dashboard in <span class="font-bold text-white" x-text="countdown">3</span>s...
                </p>

                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-left max-w-md mx-auto mb-6 space-y-2 text-xs">
                    <div class="flex items-center justify-between text-slate-300">
                        <span class="text-slate-500">Admin Account:</span>
                        <span class="font-medium text-white" x-text="form.admin_email"></span>
                    </div>
                    <div class="flex items-center justify-between text-slate-300">
                        <span class="text-slate-500">Database:</span>
                        <span class="font-mono text-emerald-400" x-text="form.db_name"></span>
                    </div>
                    <div class="flex items-center justify-between text-slate-300">
                        <span class="text-slate-500">Security Lock:</span>
                        <span class="font-mono text-blue-400">.installed (0444 Protected)</span>
                    </div>
                </div>

                <a :href="redirectUrl || '/'"
                   class="inline-flex items-center gap-2 px-8 py-3 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold transition-all shadow-lg shadow-blue-600/30">
                    Launch Application Dashboard &rarr;
                </a>
            </div>

        </div>

        <!-- Footer Notice -->
        <div class="text-center mt-6 text-xs text-slate-500">
            Nexus cPanel Installer &bull; Libsodium 256-Bit Engine &bull; Zero-SSH Compatible
        </div>

    </div>

    <script>
        function installerWizard() {
            return {
                step: 1,
                prereqs: <?= json_encode($prerequisites, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                csrfToken: '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>',
                testingDb: false,
                dbStatus: null,
                dbMessage: '',
                installing: false,
                errorMessage: '',
                redirectUrl: '',
                countdown: 3,

                form: {
                    db_host: '127.0.0.1',
                    db_port: 3306,
                    db_name: '',
                    db_user: '',
                    db_pass: '',
                    app_name: 'Nexus Application',
                    app_url: '<?= htmlspecialchars($detectedUrl, ENT_QUOTES, 'UTF-8') ?>',
                    admin_name: 'Administrator',
                    admin_email: '',
                    admin_password: '',
                    admin_password_confirm: ''
                },

                init() {
                    this.$nextTick(() => {
                        if (window.lucide) window.lucide.createIcons();
                    });
                },

                canSubmitAdmin() {
                    return this.form.app_name &&
                           this.form.app_url &&
                           this.form.admin_name &&
                           this.form.admin_email &&
                           this.form.admin_password.length >= 8 &&
                           this.form.admin_password === this.form.admin_password_confirm;
                },

                async testConnection() {
                    this.testingDb = true;
                    this.dbStatus = null;
                    this.dbMessage = '';

                    try {
                        const res = await fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                action: 'test_db',
                                _token: this.csrfToken,
                                db_host: this.form.db_host,
                                db_port: this.form.db_port,
                                db_name: this.form.db_name,
                                db_user: this.form.db_user,
                                db_pass: this.form.db_pass
                            })
                        });

                        const data = await res.json();
                        this.dbStatus = data.success;
                        this.dbMessage = data.message;
                    } catch (err) {
                        this.dbStatus = false;
                        this.dbMessage = 'Network error connecting to installer.';
                    } finally {
                        this.testingDb = false;
                        this.$nextTick(() => {
                            if (window.lucide) window.lucide.createIcons();
                        });
                    }
                },

                async executeInstallation() {
                    if (!this.canSubmitAdmin()) return;

                    this.installing = true;
                    this.errorMessage = '';

                    try {
                        const res = await fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                action: 'install',
                                _token: this.csrfToken,
                                ...this.form
                            })
                        });

                        const data = await res.json();
                        if (data.success) {
                            this.step = 4;
                            this.redirectUrl = data.redirect || '/';
                            this.countdown = 3;
                            const timer = setInterval(() => {
                                this.countdown--;
                                if (this.countdown <= 0) {
                                    clearInterval(timer);
                                    window.location.href = this.redirectUrl;
                                }
                            }, 1000);
                            this.$nextTick(() => {
                                if (window.lucide) window.lucide.createIcons();
                            });
                        } else {
                            this.errorMessage = data.error || data.message || 'Installation encountered an error.';
                        }
                    } catch (err) {
                        this.errorMessage = 'Network connection failed during installation.';
                    } finally {
                        this.installing = false;
                    }
                }
            };
        }
    </script>
</body>
</html>
