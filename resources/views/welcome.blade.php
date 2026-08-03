<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.HR - Workforce Management for Team-Scoped Organizations</title>
        <meta name="description" content="Positions, employees, and leave — managed with team-scoped authorization from day one. Built on Laravel 12 and Jetstream Teams, part of the Dot Ecosystem.">

        @fonts

        <!-- Styles / Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            @keyframes float {
                0%, 100% { transform: translateY(0px); }
                50% { transform: translateY(-20px); }
            }
            .float-animation {
                animation: float 6s ease-in-out infinite;
            }
            @keyframes slideInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .slide-in-up {
                animation: slideInUp 0.8s ease-out forwards;
            }
        </style>
    </head>
    <body class="bg-gray-900 text-gray-100 antialiased">

        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" x-data="{ scrolled: false, mobileMenuOpen: false }"
                @scroll.window="scrolled = window.pageYOffset > 50"
                :class="scrolled ? 'bg-gray-900/95 backdrop-blur-xl shadow-lg border-b border-gray-800' : 'bg-transparent'">
            <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <!-- Logo -->
                    <a href="/" class="flex items-center gap-3 group">
                        <div class="relative">
                            <img src="{{ asset('images/logo.png') }}" alt="Dot.HR" class="h-14 w-auto transform group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute -top-1 -right-1 w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <p class="hidden sm:block text-xs text-indigo-400 font-medium border-l border-gray-700 pl-3">Workforce Management</p>
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="hidden md:flex items-center gap-8">
                        <a href="#features" class="text-gray-300 hover:text-indigo-400 transition-colors font-medium">Features</a>
                        <a href="#capabilities" class="text-gray-300 hover:text-indigo-400 transition-colors font-medium">Capabilities</a>
                        <a href="#principles" class="text-gray-300 hover:text-indigo-400 transition-colors font-medium">Principles</a>
                    </div>

                    <!-- Auth Links -->
                    @if (Route::has('login'))
                        <div class="flex items-center gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="hidden sm:flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-indigo-500/30 transform hover:scale-105">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                    <span>Dashboard</span>
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="hidden sm:block px-4 py-2 text-gray-300 hover:text-white transition-colors font-medium">
                                    Sign In
                                </a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-indigo-500/30 transform hover:scale-105">
                                        <span>Get Started</span>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                        </svg>
                                    </a>
                                @endif
                            @endauth

                            <!-- Mobile menu button -->
                            <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 text-gray-400 hover:text-white">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                                    <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>

                <!-- Mobile Menu -->
                <div x-show="mobileMenuOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform scale-95"
                     x-transition:enter-end="opacity-100 transform scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 transform scale-100"
                     x-transition:leave-end="opacity-0 transform scale-95"
                     class="md:hidden mt-4 py-4 border-t border-gray-800"
                     style="display: none;">
                    <div class="flex flex-col gap-2">
                        <a href="#features" class="px-4 py-2 text-gray-300 hover:text-indigo-400 hover:bg-gray-800 rounded-lg transition-colors">Features</a>
                        <a href="#capabilities" class="px-4 py-2 text-gray-300 hover:text-indigo-400 hover:bg-gray-800 rounded-lg transition-colors">Capabilities</a>
                        @guest
                            <a href="{{ route('login') }}" class="px-4 py-2 text-gray-300 hover:text-indigo-400 hover:bg-gray-800 rounded-lg transition-colors">Sign In</a>
                        @endguest
                    </div>
                </div>
            </nav>
        </header>

        <!-- Hero Section -->
        <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">
            <!-- Photographic Background: real diverse-team-collaborating-in-office photo by Vitaly Gariev (@silverkblack), unsplash.com/photos/fm4B1xWEIsU -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1758873269276-9518d0cb4a0b?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/85 to-gray-900/60"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-gray-900 via-gray-900/40 to-transparent"></div>

            <!-- Floating Elements -->
            <div class="absolute top-20 left-10 w-64 h-64 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation"></div>
            <div class="absolute bottom-20 right-10 w-96 h-96 bg-emerald-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation" style="animation-delay: 2s;"></div>

            <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
                <div class="grid lg:grid-cols-2 gap-12 items-center">
                    <!-- Left Column -->
                    <div class="space-y-8 slide-in-up">
                        <div class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-indigo-400 text-sm font-medium">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                            </span>
                            <span>Team-Scoped Workforce Management</span>
                        </div>

                        <h2 class="text-5xl lg:text-7xl font-bold leading-tight">
                            <span class="bg-gradient-to-r from-white via-gray-100 to-gray-300 bg-clip-text text-transparent">Manage Your</span><br>
                            <span class="bg-gradient-to-r from-indigo-400 via-indigo-500 to-indigo-600 bg-clip-text text-transparent">Workforce, Not Workers</span>
                        </h2>

                        <p class="text-xl text-gray-400 leading-relaxed max-w-xl">
                            Positions, employee records, and leave requests — organized per team, with role-based authorization built in from the first commit. Built on Laravel 12 and Jetstream Teams as part of the Dot Ecosystem.
                        </p>

                        <!-- Domain Highlights -->
                        <div class="grid grid-cols-3 gap-6 py-6">
                            <div class="text-center">
                                <div class="text-lg font-bold text-white mb-1">Positions</div>
                                <div class="text-sm text-gray-400">Role definitions</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-white mb-1">Employees</div>
                                <div class="text-sm text-gray-400">Employment records</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-white mb-1">Leave</div>
                                <div class="text-sm text-gray-400">Request workflow</div>
                            </div>
                        </div>

                        @guest
                            <div class="flex flex-wrap gap-4">
                                <a href="{{ route('register') }}" class="group flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-indigo-500/30 transform hover:scale-105">
                                    <span>Create a Team</span>
                                    <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                </a>
                                <a href="#features" class="flex items-center gap-2 px-8 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                                    </svg>
                                    <span>See What's Built</span>
                                </a>
                            </div>
                            <p class="text-sm text-gray-500">Team-scoped from the first commit &middot; No individual scoring or ranking, ever</p>
                        @endguest
                    </div>

                    <!-- Right Column - Dashboard Preview -->
                    <div class="relative slide-in-up" style="animation-delay: 0.2s;">
                        <!-- Main Dashboard Card -->
                        <div class="relative bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl border border-gray-700 shadow-2xl overflow-hidden transform hover:scale-105 transition-transform duration-500">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-6">
                                    <h3 class="text-lg font-bold text-white">Team Workforce</h3>
                                    <div class="flex items-center gap-2 px-3 py-1.5 bg-green-500/10 border border-green-500/20 rounded-full">
                                        <span class="relative flex h-2 w-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                                        </span>
                                        <span class="text-xs text-green-400 font-semibold">Team-Scoped</span>
                                    </div>
                                </div>

                                <!-- Sample Entity Cards -->
                                <div class="grid grid-cols-2 gap-3 mb-6">
                                    <div class="bg-gradient-to-br from-indigo-500/10 to-indigo-600/5 rounded-xl p-4 border border-indigo-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-indigo-400 font-medium">Positions</p>
                                            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">Job/role</p>
                                        <p class="text-xs text-gray-400">definitions</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-emerald-500/10 to-emerald-600/5 rounded-xl p-4 border border-emerald-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-emerald-400 font-medium">Employees</p>
                                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">Minimal PII</p>
                                        <p class="text-xs text-gray-400">name, contact, role</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/5 rounded-xl p-4 border border-purple-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-purple-400 font-medium">Leave Requests</p>
                                            <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">Pending</p>
                                        <p class="text-xs text-gray-400">approve / deny</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-amber-500/10 to-amber-600/5 rounded-xl p-4 border border-amber-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-amber-400 font-medium">Access</p>
                                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">Admin-gated</p>
                                        <p class="text-xs text-gray-400">create / edit / delete</p>
                                    </div>
                                </div>

                                <!-- Domain Entity Legend -->
                                <div class="bg-gray-900/50 rounded-xl p-4 border border-gray-700/50">
                                    <p class="text-xs text-gray-400 font-medium mb-3">Built on Laravel 12 + Jetstream Teams</p>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-xs text-gray-300">Fortify</span>
                                        <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-xs text-gray-300">Sanctum</span>
                                        <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-xs text-gray-300">Team Policies</span>
                                        <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-xs text-gray-300">Feature Tests</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Floating Badge -->
                        <div class="absolute -top-6 -right-6 bg-gradient-to-r from-green-500 to-emerald-500 text-white px-6 py-3 rounded-2xl shadow-2xl shadow-green-500/30 flex items-center gap-2 float-animation transform hover:scale-110 transition-transform">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="font-bold">Team-Scoped</span>
                        </div>

                        <!-- Principle Badge -->
                        <div class="absolute -bottom-6 -left-6 bg-gradient-to-r from-indigo-500 to-purple-500 text-white px-6 py-3 rounded-2xl shadow-2xl shadow-indigo-500/30 flex items-center gap-2 float-animation transform hover:scale-110 transition-transform" style="animation-delay: 1s;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="font-bold">Work, Not Workers</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-gray-900/50 relative overflow-hidden">
            <!-- Background Elements -->
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-indigo-500/5 to-transparent"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16 fade-in-up">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-indigo-400 text-sm font-medium mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                        </svg>
                        <span>Core Domain</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Three Entities,<br>
                        <span class="bg-gradient-to-r from-indigo-400 to-indigo-600 bg-clip-text text-transparent">Built and Authorized</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">
                        What Dot.HR's MVP actually manages today — team-scoped from the first commit
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <!-- Feature 1 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-indigo-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Positions</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Job and role definitions scoped to your team's org structure — describing the work itself, not any individual doing it.</p>
                    </div>

                    <!-- Feature 2 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-emerald-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Employee Records</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Deliberately minimal PII: name, work email/phone, position, employment type, status, and start/end date. No ID numbers, no salary, no medical data.</p>
                    </div>

                    <!-- Feature 3 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-purple-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Leave Requests</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">A pending / approved / denied workflow tied to one employee at a time, with the free-text reason field treated as sensitive by default.</p>
                    </div>

                    <!-- Feature 4 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-amber-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Role-Gated Mutations</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Creating, updating, or deleting a Position, Employee, or Leave Request requires the team's admin role or ownership. Viewing stays open to any team member.</p>
                    </div>

                    <!-- Feature 5 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-red-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-red-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Cross-Team Isolation</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Every model is team-scoped via <code class="text-indigo-300">team_id</code>. Feature tests explicitly cover cross-team access denial, not just the happy path.</p>
                    </div>

                    <!-- Feature 6 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-indigo-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-cyan-500 to-cyan-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-cyan-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-indigo-400 transition-colors">Jetstream Teams Shell</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Laravel 12, Jetstream Teams, Fortify, and Sanctum underpin the whole platform, matching the rest of the Dot Ecosystem's team-scoped services.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Capabilities Section -->
        <section id="capabilities" class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16 fade-in-up">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full text-indigo-400 text-sm font-medium mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <span>Where Dot.HR Sits</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Part of<br>
                        <span class="bg-gradient-to-r from-indigo-400 to-cyan-400 bg-clip-text text-transparent">the Dot Ecosystem</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">
                        Dot.HR owns the people domain and keeps clear boundaries with the platforms around it
                    </p>
                </div>

                <div class="grid lg:grid-cols-2 gap-8 mb-8">
                    <!-- Left Column -->
                    <div class="space-y-6">
                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-indigo-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">HR Owns the Roster</h3>
                                    <p class="text-gray-400 leading-relaxed">Positions, employees, and leave live here. Payroll execution is explicitly out of scope — Dot.Billing owns money movement, and no billing integration exists in this codebase yet.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-purple-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Role/Skill Definitions, Not Task Assignment</h3>
                                    <p class="text-gray-400 leading-relaxed">HR owns what a role is. Who does what today belongs to Dot.Tasks / Dot.Projects — no integration exists between the two yet.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-emerald-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">PII Structurally Excluded</h3>
                                    <p class="text-gray-400 leading-relaxed">Employment records are excluded from the ecosystem's shared knowledge graph at the type level — not filtered at review time, but absent from the outbound data model entirely.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-6">
                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-orange-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">MVP Scaffolded, Honestly Labeled</h3>
                                    <p class="text-gray-400 leading-relaxed">This platform is built and, as of the latest change log entry, executed and tested for real — 41 of 41 non-skipped tests passing. Roadmap items stay marked as roadmap, not shipped.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-pink-500 to-pink-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-pink-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">No Individual Scoring, Ever</h3>
                                    <p class="text-gray-400 leading-relaxed">No productivity scores, attendance streaks, or peer comparison of any kind. If a recognition feature ships, it targets team-level coverage goals — never an individual.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-indigo-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-cyan-500 to-cyan-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-cyan-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Aggregation Layer — Roadmap</h3>
                                    <p class="text-gray-400 leading-relaxed">Publishing workforce-structure Knowledge Packs to Dot.Brain is design intent, not built. There is no outbound integration path from this codebase today.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Principles Section / CTA -->
        <section id="principles" class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <!-- Photographic Background: real team-collaborating-around-a-computer-in-an-office photo by Vitaly Gariev (@silverkblack), unsplash.com/photos/UikYLDQj9_I -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1758873268745-dd2cf0d677b5?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gray-900/90"></div>
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/20 via-indigo-500/10 to-transparent"></div>

            <div class="relative z-10 max-w-4xl mx-auto text-center fade-in-up">
                <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                    Work, Not<br>
                    <span class="bg-gradient-to-r from-indigo-400 to-indigo-600 bg-clip-text text-transparent">Workers</span>
                </h2>
                <p class="text-xl text-gray-400 mb-10 max-w-2xl mx-auto">
                    Dot.HR publishes knowledge about roles, skills, and workforce structure — never knowledge that models, ranks, or predicts an identified individual. That's a structural boundary in the data model, not a policy promise.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4 mb-8">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-10 py-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-indigo-500/30 transform hover:scale-105">
                            <span>Create Your Team</span>
                            <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </a>
                        <a href="{{ route('login') }}" class="flex items-center gap-2 px-10 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                            <span>Sign In</span>
                        </a>
                    </div>
                    <p class="text-sm text-gray-500">Team-scoped authorization on every model &middot; No individual scoring, ever</p>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-gray-800 bg-gray-900/50">
            <div class="max-w-7xl mx-auto">
                <div class="grid md:grid-cols-4 gap-8 mb-8">
                    <!-- Brand -->
                    <div class="col-span-1">
                        <img src="{{ asset('images/logo.png') }}" alt="Dot.HR" class="h-12 w-auto mb-4">
                        <p class="text-gray-400 text-sm">
                            Workforce management for team-scoped organizations, part of the Dot Ecosystem.
                        </p>
                    </div>

                    <!-- Links -->
                    <div>
                        <h3 class="text-white font-semibold mb-4">Product</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#features" class="text-gray-400 hover:text-indigo-400 transition-colors">Features</a></li>
                            <li><a href="#capabilities" class="text-gray-400 hover:text-indigo-400 transition-colors">Capabilities</a></li>
                            <li><a href="#principles" class="text-gray-400 hover:text-indigo-400 transition-colors">Principles</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-white font-semibold mb-4">Company</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">About Us</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Contact</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Careers</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-white font-semibold mb-4">Legal</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Privacy Policy</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Terms of Service</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-indigo-400 transition-colors">Cookie Policy</a></li>
                        </ul>
                    </div>
                </div>

                <div class="pt-8 border-t border-gray-800 flex flex-col md:flex-row items-center justify-between gap-4">
                    <p class="text-gray-400 text-sm">
                        &copy; {{ date('Y') }} {{ config('app.name', 'Dot.HR') }}. All rights reserved.
                    </p>
                    <p class="text-gray-500 text-xs">
                        v{{ app()->version() }}
                    </p>
                </div>
            </div>
        </footer>

    </body>
</html>
