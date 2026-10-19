<?php
// Ported from the invoice app's Ai_providers library: same logic, but its own settings file, tables and encryption key.

/**
 * AI providers registry: free-tier vision providers for the payment bot.
 *
 * - Models: each provider's model list is fetched, filtered to vision models,
 *   ranked, and the best one is picked automatically ("auto" model). On a free
 *   plan only free models are picked; on a paid account the best overall is.
 * - Status: every request updates the provider's status (ok, rate limited,
 *   invalid key, error) and the quota windows the provider reports in its
 *   x-ratelimit-* headers (or, for Gemini, in its 429 quota errors).
 * - Usage: every request is counted locally per provider per day (requests,
 *   errors, tokens), because most free tiers expose no usage API.
 *
 * Config lives in the EMAIL settings under "ai_providers":
 *   { "order": [...], "providers": { "<id>": {"enabled":1, "api_key":"<encrypted>",
 *     "model":"auto|<model id>", "plan":"auto|free|paid", "daily_limit":0} } }
 * Runtime state lives in tables ai_provider_state and ai_provider_usage.
 */
class WiAiProviders
{
    protected $CI;
    protected $last_errors = array();
    protected $last_headers = array();
    protected $tables_ready = false;
    protected $state_cache = array();

    protected $multi = null;
    /** Models already tried in the current request (so a second round uses different ones), and how many to try per provider. */
    protected $tried = array();
    protected $chain_cap = 4;
    /** Optional 'provider:model' to try first for this request (e.g. the model chosen for the bot). */
    public $prefer = '';
    protected $blocked = array();
    /** Tokens and requests of the current vision_json() call (all attempts, all providers). */
    public $usage = array('tokens_in'=>0, 'tokens_out'=>0, 'requests'=>0);

    /** '' = the system's keys; otherwise a client's own store and their own provider state. */
    protected $scope = '';
    public function __construct($scope = '') { $this->scope = preg_replace('/[^a-z0-9]/', '', (string) $scope); }
    protected function store() { return $this->scope === '' ? 'ai_providers.json' : 'ai_providers_'.$this->scope.'.json'; }
    protected function sk($id) { return $this->scope === '' ? $id : $this->scope.'|'.$id; }

    public static function catalog()
    {
        return array(
            'gemini' => array(
                'name' => 'Google Gemini',
                'tagline' => 'Google AI Studio free tier · no card needed',
                'api' => 'gemini',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'default_model' => 'gemini-3.5-flash-lite',
                'json_mode' => true,
                'signup_url' => 'https://aistudio.google.com/app/apikey',
                'usage_url' => 'https://aistudio.google.com/usage',
                'key_hint' => 'AIza...',
                'color' => '#1a73e8',
                'plan_note' => 'Pro models usually need a billing account; Flash, Flash-Lite and Gemma are free.',
                'free_daily_small' => true, // free plan: Flash ~20 requests a day, Flash-Lite ~500 — background work goes elsewhere
                'free_prefer' => '/flash-lite/', // free plan: the models with the larger daily limit go first
                // Free-plan limits per model (requests a minute, requests a day; first match wins). 0 a day = never used on the free plan.
                // Counted here before sending, so a model is rested before Google answers 429.
                'model_limits' => array('/gemini-2\.5-flash-lite/' => array(10, 20), '/flash-lite/' => array(15, 500), '/gemma/' => array(30, 14400),
                    '/omni|banana|antigravity|deep-research|robotics|tts|live|transcribe|image/' => array(0, 0), '/flash/' => array(5, 20)),
            ),
            'groq' => array(
                'name' => 'Groq',
                'tagline' => 'Free plan · very fast; vision models are detected by a test image',
                'api' => 'openai',
                'base_url' => 'https://api.groq.com/openai/v1',
                'default_model' => 'qwen/qwen3.8-27b',
                'json_mode' => true,
                'probe_vision' => true,
                'signup_url' => 'https://console.groq.com/keys',
                'usage_url' => 'https://console.groq.com/settings/limits',
                'key_hint' => 'gsk_...',
                'color' => '#f55036',
                'limit_labels' => array('requests' => 'Requests per day', 'tokens' => 'Tokens per minute'),
            ),
            'mistral' => array(
                'name' => 'Mistral AI',
                'tagline' => 'Free "Experiment" plan (phone verification)',
                'api' => 'openai',
                'base_url' => 'https://api.mistral.ai/v1',
                'default_model' => 'mistral-small-latest',
                'json_mode' => true,
                'signup_url' => 'https://console.mistral.ai/api-keys',
                'usage_url' => 'https://admin.mistral.ai/plateforme/limits',
                'key_hint' => 'Mistral API key',
                'zero_quota_hint' => 'Mistral reports a limit of 0 requests/minute for this key. Activate the free Experiment plan at admin.mistral.ai (Plans, phone verification) for the workspace that owns this key, then Refresh.',
                'color' => '#fa520f',
            ),
            'flexai' => array(
                'name' => 'FlexAI',
                'tagline' => 'Pay per token · key without a card; add credit to use it',
                'api' => 'openai',
                'base_url' => 'https://api.flex.ai/v1',
                'default_model' => '',
                'json_mode' => true,
                'probe_vision' => true,
                'signup_url' => 'https://flex.ai',
                'usage_url' => 'https://flex.ai/models',
                'key_hint' => 'sk-...',
                'color' => '#7c3aed',
                'zero_quota_hint' => 'FlexAI says the account has no credit (HTTP 402). Add credit in the FlexAI dashboard, then Refresh.',
            ),
            'openrouter' => array(
                'name' => 'OpenRouter',
                'tagline' => 'Free models (":free") · paid models only with credits',
                'api' => 'openai',
                'base_url' => 'https://openrouter.ai/api/v1',
                'default_model' => 'google/gemma-3-27b-it:free',
                'json_mode' => false,
                'free_only_suffix' => ':free',
                'signup_url' => 'https://openrouter.ai/keys',
                'usage_url' => 'https://openrouter.ai/activity',
                'key_hint' => 'sk-or-...',
                'color' => '#6467f2',
                'per_model_limits' => true,
            ),
            'opencode' => array(
                'name' => 'OpenCode Zen',
                'models_public' => true, // the full list is published without a key; the key's own list is shorter (what the account may use)
                'tagline' => 'Needs Zen credit: its free models work only inside the OpenCode app',
                'api' => 'openai',
                'base_url' => 'https://opencode.ai/zen/v1',
                'default_model' => 'big-pickle',
                'json_mode' => false,
                'free_pattern' => '/(-free|^big-pickle)$/',
                'signup_url' => 'https://opencode.ai/auth',
                'usage_url' => 'https://opencode.ai/workspace',
                'key_hint' => 'sk-...',
                'color' => '#211e1e',
                'zero_quota_hint' => 'OpenCode Zen free models can only be used from inside the OpenCode app. To use Zen here, add credit (opencode.ai → workspace → billing) and set the plan to Paid; otherwise turn it off and use Kilo Code (free without a key).',
                'per_model_limits' => true,
            ),
            'opencode_go' => array(
                'name' => 'OpenCode Go',
                'models_public' => true, // the full list is published without a key; the key's own list is shorter (what the account may use)
                'tagline' => 'Subscription · every model of the plan included (monthly usage limits)',
                'api' => 'openai',
                'base_url' => 'https://opencode.ai/zen/go/v1',
                'default_model' => 'deepseek-v4-flash',
                'json_mode' => false,
                'skip_models' => '/luna|-exp$/',
                'session_header' => 'x-opencode-session', // models served only on another API route (responses) or experimental
                'signup_url' => 'https://opencode.ai/go',
                'usage_url' => 'https://opencode.ai/workspace',
                'key_hint' => 'OpenCode API key (Go subscription)',
                'color' => '#3b3b3b',
                'per_model_limits' => true,
            ),
            'kilo' => array(
                'name' => 'Kilo Code',
                'tagline' => 'Kilo Gateway · free models (":free") · paid models with Kilo credit',
                'api' => 'openai',
                'base_url' => 'https://api.kilo.ai/api/gateway',
                'default_model' => 'kilo-auto/free',
                'json_mode' => false,
                'free_pattern' => '/(:free|-free|^kilo-auto\/free)$/',
                'models_format' => 'openrouter',
                'signup_url' => 'https://app.kilo.ai/profile',
                'usage_url' => 'https://app.kilo.ai/usage',
                'key_hint' => 'Kilo API key (optional for free models)',
                'key_optional' => true,
                'color' => '#f8f675',
                'per_model_limits' => true,
            ),
        );
    }

    /* ---------------------------------------------------------------- config */

    /** Raw stored configuration, with the legacy Gemini/Mistral fields migrated in. */
    public function stored_config()
    {
        $config = wi_read($this->store(), array());
        if( !is_array($config) ) $config = array();
        if( !isset($config['providers']) || !is_array($config['providers']) ) $config['providers'] = array();
        if( empty($config['order']) || !is_array($config['order']) ) $config['order'] = array_keys(self::catalog());
        $config['order'] = $this->normalize_order($config['order']);
        $config['mode'] = isset($config['mode']) && $config['mode'] === 'parallel' ? 'parallel' : 'sequential';
        return $config;
    }
    public function save_config($config)
    {
        wi_write($this->store(), $config);
    }

    public function normalize_order($order)
    {
        $catalog = self::catalog();
        $clean = array();
        foreach( (array) $order as $id ) {
            $id = strtolower(trim((string) $id));
            if( isset($catalog[$id]) && !in_array($id, $clean, true) ) $clean[] = $id;
        }
        foreach( array_keys($catalog) as $id ) {
            if( !in_array($id, $clean, true) ) $clean[] = $id;
        }
        return $clean;
    }

    /** Providers in fallback order with decrypted keys and the resolved model. Server-side use only. */
    public function providers()
    {
        $catalog = self::catalog();
        $config = $this->stored_config();
        $list = array();
        foreach( $config['order'] as $id ) {
            $row = isset($config['providers'][$id]) ? $config['providers'][$id] : array();
            $state = $this->get_state($id);
            $plan_setting = isset($row['plan']) && in_array($row['plan'], array('free', 'paid'), true) ? $row['plan'] : 'auto';
            $plan = $plan_setting !== 'auto' ? $plan_setting : ($state && $state['detected_plan'] ? $state['detected_plan'] : 'free');
            $model_setting = isset($row['model']) ? trim((string) $row['model']) : '';
            $auto = $model_setting === '' || $model_setting === 'auto';
            $best = $state ? ($plan === 'paid' ? $state['best_paid'] : $state['best_free']) : '';
            $custom = !empty($catalog[$id]['custom']) ? array_filter(array('name' => isset($row['name']) ? trim((string) $row['name']) : '', 'base_url' => isset($row['base_url']) ? rtrim(trim((string) $row['base_url']), '/') : '')) : array();
            $provider = array_merge($catalog[$id], $custom, array(
                'id' => $id,
                'enabled' => !empty($row['enabled']),
                'has_key' => !empty($row['api_key']),
                'api_key' => $this->decrypt(isset($row['api_key']) ? $row['api_key'] : ''),
                'api_keys' => array_values(array_filter(array_merge(array($this->decrypt(isset($row['api_key']) ? $row['api_key'] : '')), array_map(array($this, 'decrypt'), (array) (isset($row['extra_keys']) ? $row['extra_keys'] : array()))))),
                'extra_keys' => count((array) (isset($row['extra_keys']) ? $row['extra_keys'] : array())),
                'plan_setting' => $plan_setting,
                'plan' => $plan,
                'model_setting' => $auto ? 'auto' : $model_setting,
                'auto_model' => $auto,
                'model' => $auto ? ($best ? $best : $catalog[$id]['default_model']) : $model_setting,
                'daily_limit' => isset($row['daily_limit']) ? max(0, (int) $row['daily_limit']) : 0,
            ));
            $list[$id] = $provider;
        }
        return $list;
    }

    /**
     * Enabled providers that can take a request now. Healthy providers keep the configured
     * order and go first; a provider whose quota ended, whose key is invalid, or that is
     * cooling down after a rate limit moves to the end until it works again.
     */
    public function active_providers()
    {
        $healthy = array(); $degraded = array();
        foreach( $this->providers() as $id => $provider ) {
            if( $this->skip_reason($provider) !== '' ) continue;
            $health = $this->health($id);
            $provider['health'] = $health;
            if( $health['ok'] ) $healthy[$id] = $provider; else $degraded[$id] = $provider;
        }
        return $healthy + $degraded;
    }

    /** array('ok'=>bool, 'reason'=>string, 'until'=>timestamp|null) from the provider's last known state. */
    public function health($id)
    {
        $state = $this->get_state($id);
        $ok = array('ok'=>true, 'reason'=>'', 'until'=>null);
        if( !$state || !$state['status'] ) return $ok;
        if( $state['status'] === 'no_quota' ) return array('ok'=>false, 'reason'=>'Plan quota ended', 'until'=>null);
        if( $state['status'] === 'invalid_key' ) return array('ok'=>false, 'reason'=>'Invalid API key', 'until'=>null);
        $limits = $state['limits_json'] ? json_decode($state['limits_json'], true) : array();
        $since = strtotime($state['last_used_at'] ? $state['last_used_at'] : $state['updated_at']);
        // A window the provider reported as fully used stays closed until its reset time.
        if( !empty($limits['windows']) && !empty($limits['captured_at']) ) {
            foreach( $limits['windows'] as $name => $w ) {
                if( isset($w['remaining']) && (string) $w['remaining'] === '0' && isset($w['limit']) && (string) $w['limit'] !== '0' ) {
                    $until = strtotime($limits['captured_at']) + max(60, $this->duration_seconds(isset($w['reset']) ? $w['reset'] : ''));
                    if( $until > time() ) return array('ok'=>false, 'reason'=>'Quota used up ('.$name.')', 'until'=>$until);
                }
            }
        }
        if( $state['status'] === 'rate_limited' ) {
            $wait = 60;
            if( !empty($limits['retry_after']) ) $wait = max($wait, $this->duration_seconds($limits['retry_after']));
            if( !empty($limits['quota_hit']['retry_delay']) ) $wait = max($wait, $this->duration_seconds($limits['quota_hit']['retry_delay']));
            if( !empty($limits['quota_hit']['violations']) ) {
                foreach( $limits['quota_hit']['violations'] as $v ) {
                    if( stripos((string) $v['quota'], 'PerDay') !== false ) {
                        // Google resets daily quotas at midnight Pacific time.
                        $reset = new DateTime('tomorrow', new DateTimeZone('America/Los_Angeles'));
                        $wait = max($wait, $reset->getTimestamp() - $since);
                    }
                }
            }
            if( $since + $wait > time() ) return array('ok'=>false, 'reason'=>'Rate limited', 'until'=>$since + $wait);
        }
        if( $state['status'] === 'error' && $since > time() - 300 ) return array('ok'=>false, 'reason'=>'Recent error', 'until'=>$since + 300);
        return $ok;
    }

    /** "4m19.2s", "24.214s", "1h2m", "33s" or plain seconds -> seconds. */
    public function duration_seconds($value)
    {
        $value = trim((string) $value);
        if( $value === '' ) return 0;
        if( is_numeric($value) ) return (int) ceil((float) $value);
        $total = 0;
        if( preg_match_all('/([\d.]+)\s*(ms|h|m|s)/', $value, $m, PREG_SET_ORDER) ) {
            foreach( $m as $part ) {
                $n = (float) $part[1];
                $total += $part[2] === 'h' ? $n * 3600 : ($part[2] === 'm' ? $n * 60 : ($part[2] === 's' ? $n : $n / 1000));
            }
        }
        return (int) ceil($total);
    }

    public function skip_reason($provider)
    {
        if( !$provider['enabled'] ) return 'Disabled';
        if( $provider['api_key'] === '' && empty($provider['key_optional']) ) return 'No API key';
        if( $provider['base_url'] === '' ) return 'No Base URL';
        if( !$this->model_allowed($provider, $provider['model']) ) return 'Paid model on a free plan';
        if( $provider['daily_limit'] > 0 ) {
            $usage = $this->usage_summary($provider['id']);
            if( $usage['today']['requests'] >= $provider['daily_limit'] ) return 'Daily limit reached';
        }
        return '';
    }

    /** Routers that sell paid models (OpenRouter) are locked to free models unless the plan is paid. */
    public function model_allowed($provider, $model, $plan = null)
    {
        $plan = $plan !== null ? $plan : (isset($provider['plan']) ? $provider['plan'] : 'free');
        if( trim((string) $model) === '' ) return false;
        if( !empty($provider['skip_models']) && preg_match($provider['skip_models'], strtolower((string) $model)) ) return false; // listed for choosing by hand only
        if( !empty($provider['free_only_suffix']) && $plan !== 'paid' ) {
            $suffix = $provider['free_only_suffix'];
            return substr((string) $model, -strlen($suffix)) === $suffix;
        }
        if( !empty($provider['free_pattern']) && $plan !== 'paid' ) return (bool) preg_match($provider['free_pattern'], (string) $model);
        return true;
    }

    public function last_errors()
    {
        return $this->last_errors;
    }

    /* -------------------------------------------------------------- requests */

    /**
     * Ask the active providers in order until one returns a JSON object.
     * Returns array('ok'=>bool, 'data'=>array, 'provider'=>id, 'model'=>string, 'errors'=>array).
     */
    public function vision_json($prompt, $image_data, $mime, $only = null)
    {
        $this->last_errors = array();
        $this->usage = array('tokens_in'=>0, 'tokens_out'=>0, 'requests'=>0);
        $this->tried = array(); $this->blocked = array(); $this->chain_cap = $image_data === null ? 5 : 4;
        if( $this->prefer !== '' && strpos($this->prefer, ':') !== false ) {
            list($pid, $pmodel) = explode(':', $this->prefer, 2);
            $all = $this->providers();
            if( isset($all[$pid]) && $all[$pid]['api_key'] !== '' && $all[$pid]['enabled'] ) {
                $pr = $all[$pid];
                $res = $this->request($pr, $pr['api_key'], $pmodel, $prompt, $image_data, $mime, true);
                if( $res['ok'] ) { $json = $this->parse_json($res['text']); if( is_array($json) ) return array('ok'=>true, 'data'=>$json, 'provider'=>$pid, 'model'=>$pmodel, 'errors'=>array(), 'preferred'=>true); }
                $this->last_errors[$pid.' '.$pmodel] = $res['ok'] ? 'Returned text that is not valid JSON.' : $res['error'];
                $this->tried[$pid][] = $pmodel;
            }
        }
        $first = $this->vision_json_round($prompt, $image_data, $mime, $only);
        if( $first['ok'] ) return $first;
        // Second round: other models of every provider (busy / rate-limited / empty answers are often model-specific).
        usleep(1500000);
        $this->chain_cap = 12;
        $second = $this->vision_json_round($prompt, $image_data, $mime, $only);
        if( $second['ok'] ) return $second;
        return $first['error'] === $second['error'] ? $second : array('ok'=>false, 'error'=>$second['error'], 'errors'=>$this->last_errors);
    }

    protected function vision_json_round($prompt, $image_data, $mime, $only = null)
    {
        $providers = array_diff_key($this->active_providers(), $this->blocked);
        if( $only !== null ) $providers = array_intersect_key($providers, array_flip((array) $only));
        if( !$providers ) {
            return array('ok'=>false, 'error'=>'No enabled AI provider with an API key.', 'errors'=>array());
        }
        if( $this->mode() === 'parallel' && count($providers) > 1 && class_exists('Fiber') && function_exists('curl_multi_init') ) {
            return $this->vision_json_parallel($providers, $prompt, $image_data, $mime);
        }
        foreach( $providers as $id => $provider ) {
            $r = $this->try_provider($id, $provider, $prompt, $image_data, $mime);
            if( $r['ok'] ) return $r + array('errors'=>$this->last_errors, 'mode'=>'sequential');
        }
        return array('ok'=>false, 'error'=>'All AI providers failed: '.$this->format_errors($this->last_errors), 'errors'=>$this->last_errors);
    }

    /** 'sequential' (in order, next one on failure) or 'parallel' (all at once, fastest valid answer wins). */
    public function mode()
    {
        $c = $this->stored_config();
        return isset($c['mode']) && $c['mode'] === 'parallel' ? 'parallel' : 'sequential';
    }

    /** Every active provider starts at the same moment; the first valid JSON answer wins, the rest are cancelled. */
    protected function vision_json_parallel($providers, $prompt, $image_data, $mime)
    {
        $this->multi = array('mh'=>curl_multi_init(), 'wait'=>array());
        $winner = null;
        try {
            foreach( $providers as $id => $provider ) {
                $f = new \Fiber(function() use ($id, $provider, $prompt, $image_data, $mime) { return $this->try_provider($id, $provider, $prompt, $image_data, $mime); });
                $f->start();
                if( $f->isTerminated() && $f->getReturn()['ok'] ) { $winner = $f->getReturn(); break; }
            }
            $mh = $this->multi['mh'];
            while( !$winner && $this->multi['wait'] ) {
                curl_multi_exec($mh, $running);
                if( curl_multi_select($mh, 1.0) === -1 ) usleep(50000);
                while( !$winner && ($info = curl_multi_info_read($mh)) ) {
                    $key = spl_object_id($info['handle']);
                    if( !isset($this->multi['wait'][$key]) ) continue;
                    $f = $this->multi['wait'][$key]; unset($this->multi['wait'][$key]);
                    $f->resume(array('result'=>$info['result']));
                    if( $f->isTerminated() && $f->getReturn()['ok'] ) $winner = $f->getReturn();
                }
            }
        } finally {
            curl_multi_close($this->multi['mh']);
            $this->multi = null;
        }
        if( $winner ) return $winner + array('errors'=>$this->last_errors, 'mode'=>'parallel');
        return array('ok'=>false, 'error'=>'All AI providers failed: '.$this->format_errors($this->last_errors), 'errors'=>$this->last_errors);
    }

    /** One provider's model chain. array('ok'=>true, 'data', 'provider', 'model') or array('ok'=>false). */
    /** One provider: its keys one after the other — a key that reached its limit (or was refused) rests and the next key takes over. */
    protected function try_provider($id, $provider, $prompt, $image_data, $mime)
    {
        $keys = !empty($provider['api_keys']) ? $provider['api_keys'] : array($provider['api_key']);
        if( count($keys) <= 1 ) return $this->try_provider_key($id, $provider, $prompt, $image_data, $mime);
        $cool = wi_read('ai_key_cool.json', array()); $ck = $this->scope.'|'.$id; $now = time(); $r = array('ok'=>false);
        foreach( $keys as $i => $k ) {
            $kh = substr(md5($k), 0, 10); if( isset($cool[$ck][$kh]) && $cool[$ck][$kh] > $now ) continue; // resting
            $provider['api_key'] = $k;
            $r = $this->try_provider_key($id, $provider, $prompt, $image_data, $mime);
            if( !empty($r['ok']) ) return $r;
            $st = isset($r['status']) ? (int) $r['status'] : 0;
            if( !in_array($st, array(429, 402, 401, 403), true) && empty($r['zero']) ) return $r; // not a key problem: the next key would fail the same way
            $rest = !empty($r['zero']) || $st === 402 ? 6 * 3600 : ($st === 429 ? 900 : 24 * 3600); // limit reached: a while · no credit / refused: longer
            wi_update('ai_key_cool.json', function ($C) use ($ck, $kh, $rest) { $C[$ck][$kh] = time() + $rest; return $C; });
            $this->last_errors[$id.' key '.($i + 1)] = 'key '.($i + 1).' resting ('.$st.')';
        }
        return $r;
    }

    protected function try_provider_key($id, $provider, $prompt, $image_data, $mime)
    {
        {
            $models = $this->model_chain($provider, $image_data !== null);
            // Overloaded, missing or (on Gemini, per-model) quota-exhausted models fall through to the next ranked model.
            // Empty / non-JSON replies (200) and timeouts (0) also move on to the next model.
            $retry = array(0, 200, 400, 404, 500, 502, 503, 504);
            // Gemini and OpenRouter limit each model separately: a busy/limited model moves on to the next one.
            if( $provider['api'] === 'gemini' || !empty($provider['per_model_limits']) ) $retry[] = 429;
            foreach( $models as $model ) {
                $this->tried[$id][] = $model;
                $result = $this->request($provider, $provider['api_key'], $model, $prompt, $image_data, $mime, true);
                if( $result['ok'] ) {
                    $json = $this->parse_json($result['text']);
                    if( is_array($json) ) {
                        return array('ok'=>true, 'data'=>$json, 'provider'=>$id, 'model'=>$model);
                    }
                    $result = array('ok'=>false, 'status'=>$result['status'], 'error'=>'Returned text that is not valid JSON.');
                }
                if( !empty($result['finish']) && preg_match('/^blocked|PROHIBITED|SAFETY|BLOCKLIST/', (string) $result['finish']) ) { $this->last_errors[$id.' '.$model] = $result['error']; $this->blocked[$id] = true; break; } // the same text is blocked by every model of this provider
                if( !in_array($result['status'], $retry, true) ) break;
                $this->last_errors[$id.' '.$model] = $result['error'];
            }
            $this->last_errors[$id] = $result['error'];
        }
        return array('ok'=>false, 'status'=>isset($result['status']) ? $result['status'] : 0, 'zero'=>!empty($result['zero_quota']));
    }

    /** Models to try for one request: the chosen model, then (in auto mode) the next ranked models and the default. */
    public function model_chain($provider, $need_vision = true)
    {
        // Models resting after their limit are left out FIRST, so the places in the chain go to models that can still answer
        // (e.g. Gemini Flash-Lite when every Flash model reached its daily limit).
        $state = $this->get_state($provider['id']);
        $limits = $state && $state['limits_json'] ? json_decode($state['limits_json'], true) : array();
        $cool = isset($limits['model_cooldown']) ? (array) $limits['model_cooldown'] : array();
        $self = $this; $resting = function($m) use ($cool, $self, $provider, $limits) { $ck = $self->use_key($provider, $m); return (!empty($cool[$ck]) && $cool[$ck] > time()) || $self->model_over_limit($provider, $m, $limits); };
        $tried = isset($this->tried[$provider['id']]) ? $this->tried[$provider['id']] : array();
        $chain = array();
        if( !$resting($provider['model']) ) $chain[] = $provider['model'];
        if( $provider['auto_model'] ) {
            $ranked = $state && $state['models_json'] ? json_decode($state['models_json'], true) : array();
            foreach( (array) $ranked as $m ) {
                if( count($chain) >= $this->chain_cap ) break;
                if( $need_vision === false && isset($m['text_score']) && $m['text_score'] < 45 ) continue;
                if( $need_vision && isset($m['vision']) && $m['vision'] !== true ) continue;
                if( $resting($m['id']) || in_array($m['id'], $tried, true) ) continue;
                if( !in_array($m['id'], $chain, true) && ($provider['plan'] === 'paid' || $m['free']) && $this->model_allowed($provider, $m['id']) ) $chain[] = $m['id'];
            }
            if( !in_array($provider['default_model'], $chain, true) && !$resting($provider['default_model']) && $this->model_allowed($provider, $provider['default_model']) ) $chain[] = $provider['default_model'];
        }
        if( !$chain ) $chain = array($provider['model']); // everything is resting: the chosen model is still tried once
        if( !empty($provider['free_prefer']) && ($provider['plan'] ?? 'free') !== 'paid' ) { // free plan: larger daily limits first (Gemini Flash-Lite 500 a day before Flash 20)
            $pre = array_values(array_filter($chain, function($m) use ($provider) { return preg_match($provider['free_prefer'], $m); }));
            $chain = array_values(array_unique(array_merge($pre, $chain))); }
        return array_values(array_filter($chain, function($m) use ($tried) { return !in_array($m, $tried, true); }));
    }

    public function format_errors($errors)
    {
        $parts = array();
        foreach( (array) $errors as $id => $error ) $parts[] = $id.': '.$error;
        return implode(' | ', $parts);
    }

    /** One request to one provider. Records usage and status. Returns array('ok', 'status', 'text', 'error', 'latency_ms'). */
    public function request($provider, $api_key, $model, $prompt, $image_data = null, $mime = null, $want_json = false, $max_tokens = 2000)
    {
        if( !function_exists('curl_init') ) return array('ok'=>false, 'status'=>0, 'error'=>'PHP cURL extension is missing.');
        if( $api_key === '' && empty($provider['key_optional']) ) return array('ok'=>false, 'status'=>0, 'error'=>'API key is missing.'); // free models of some gateways need no key
        if( !$this->model_allowed($provider, $model) ) return array('ok'=>false, 'status'=>0, 'error'=>'Paid models are not allowed on a free plan.');

        if( $provider['api'] === 'gemini' ) {
            $parts = array(array('text'=>$prompt));
            if( $image_data !== null ) $parts[] = array('inline_data'=>array('mime_type'=>$mime, 'data'=>base64_encode($image_data)));
            $generation = array('temperature'=>0, 'maxOutputTokens'=>$max_tokens);
            if( $want_json ) $generation['responseMimeType'] = 'application/json';
            if( preg_match('/gemini-2\.5-flash/', $model) ) {
                $generation['thinkingConfig'] = array('thinkingBudget'=>0);
            } elseif( preg_match('/gemini-([3-9]|\d{2})/', $model) ) {
                // Gemini 3+ always thinks; keep it short and leave room for the answer.
                $generation['thinkingConfig'] = array('thinkingLevel'=>'low');
                $generation['maxOutputTokens'] = max($max_tokens, 4096);
            } elseif( preg_match('/gemini-2\.5-pro/', $model) ) {
                $generation['maxOutputTokens'] = max($max_tokens, 4096);
            }
            $url = rtrim($provider['base_url'], '/').'/models/'.rawurlencode($model).':generateContent';
            $body = array('contents'=>array(array('parts'=>$parts)), 'generationConfig'=>$generation,
                // Religious treatment texts (magic, jinn, possession) trip the default filters; keep only Google's fixed limits.
                'safetySettings'=>array_map(function($c) { return array('category'=>$c, 'threshold'=>(string) (wi_sys('ai_safety') ?: 'BLOCK_ONLY_HIGH')); }, array('HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH', 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT')));
            $headers = array('Content-Type: application/json', 'x-goog-api-key: '.$api_key);
        } else {
            $content = $prompt;
            if( $image_data !== null ) {
                $content = array(
                    array('type'=>'text', 'text'=>$prompt),
                    array('type'=>'image_url', 'image_url'=>array('url'=>'data:'.$mime.';base64,'.base64_encode($image_data))),
                );
            }
            $url = rtrim($provider['base_url'], '/').'/chat/completions';
            $body = array('model'=>$model, 'temperature'=>0, 'max_tokens'=>$max_tokens, 'messages'=>array(array('role'=>'user', 'content'=>$content)));
            if( $want_json && !empty($provider['json_mode']) ) $body['response_format'] = array('type'=>'json_object');
            $headers = array_merge(array('Content-Type: application/json'), $api_key !== '' ? array('Authorization: Bearer '.$api_key) : array()); // free models of some gateways need no key
            // Gateways that route by session (OpenCode Go): one stable id per number and day, so caching works.
            if( !empty($provider['session_header']) ) $headers[] = $provider['session_header'].': wi-'.substr(md5($this->scope.'|'.date('Y-m-d').'|'.wi_sys('base_url')), 0, 24);
            if( $provider['id'] === 'openrouter' ) {
                $headers[] = 'HTTP-Referer: '.wi_sys('base_url');
                $headers[] = 'X-Title: '.wi_sys('brand_name');
            }
        }

        $started = microtime(true);
        $http = $this->http('POST', $url, $headers, json_encode($body), $image_data !== null ? 60 : 45);
        $latency = (int) round((microtime(true) - $started) * 1000);
        $decoded = json_decode($http['body'], true);
        $tokens_in = 0; $tokens_out = 0;
        if( is_array($decoded) ) {
            if( isset($decoded['usageMetadata']) ) {
                $tokens_in = isset($decoded['usageMetadata']['promptTokenCount']) ? (int) $decoded['usageMetadata']['promptTokenCount'] : 0;
                $tokens_out = isset($decoded['usageMetadata']['candidatesTokenCount']) ? (int) $decoded['usageMetadata']['candidatesTokenCount'] : 0;
            } elseif( isset($decoded['usage']) ) {
                $tokens_in = isset($decoded['usage']['prompt_tokens']) ? (int) $decoded['usage']['prompt_tokens'] : 0;
                $tokens_out = isset($decoded['usage']['completion_tokens']) ? (int) $decoded['usage']['completion_tokens'] : 0;
            }
        }
        // A plan refusal sent as 403 ("free tier only inside the app", "add credit"): no credit, not a wrong key.
        if( $http['status'] === 403 && preg_match('/free.?tier|only be used|credit|billing|balance|insufficient/i', (string) $http['error']) ) $http['status'] = 402;
        if( $http['status'] === 402 ) {
            $http['zero_quota'] = true;
            $http['error'] = 'HTTP 402 No credit. '.(!empty($provider['zero_quota_hint']) ? $provider['zero_quota_hint'] : 'Add credit on the provider dashboard.');
        }
        elseif( $http['status'] === 429 && $this->zero_quota($this->last_headers) ) {
            $http['zero_quota'] = true;
            $http['error'] = 'HTTP 429 Plan quota is 0. '.(!empty($provider['zero_quota_hint']) ? $provider['zero_quota_hint'] : 'This key has no request quota; check the plan on the provider dashboard.');
        }
        $this->record_usage($provider['id'], $http['ok'], $http['status'], $tokens_in, $tokens_out);
        $this->usage['tokens_in'] += $tokens_in; $this->usage['tokens_out'] += $tokens_out; $this->usage['requests']++;
        $this->record_status($provider, $http, $latency, $model);
        if( !$http['ok'] ) return array('ok'=>false, 'status'=>$http['status'], 'error'=>$http['error'], 'latency_ms'=>$latency, 'zero_quota'=>!empty($http['zero_quota']));

        if( $provider['api'] === 'gemini' ) {
            $text = '';
            if( isset($decoded['candidates'][0]['content']['parts']) && is_array($decoded['candidates'][0]['content']['parts']) ) {
                foreach( $decoded['candidates'][0]['content']['parts'] as $part ) {
                    if( isset($part['text']) && empty($part['thought']) ) $text .= $part['text'];
                }
            }
        } else {
            $text = isset($decoded['choices'][0]['message']['content']) ? $decoded['choices'][0]['message']['content'] : '';
            if( is_array($text) ) {
                $joined = '';
                foreach( $text as $chunk ) $joined .= isset($chunk['text']) ? $chunk['text'] : '';
                $text = $joined;
            }
        }
        $text = trim((string) $text);
        if( $text === '' ) {
            // Say why: blocked by safety, ran out of tokens while thinking, etc.
            $why = '';
            if( isset($decoded['candidates'][0]['finishReason']) ) $why = $decoded['candidates'][0]['finishReason'];
            elseif( isset($decoded['promptFeedback']['blockReason']) ) $why = 'blocked: '.$decoded['promptFeedback']['blockReason'];
            elseif( isset($decoded['choices'][0]['finish_reason']) ) $why = $decoded['choices'][0]['finish_reason'];
            return array('ok'=>false, 'status'=>$http['status'], 'error'=>'Empty response'.($why !== '' ? ' ('.$why.')' : '').'.', 'latency_ms'=>$latency, 'finish'=>$why);
        }
        return array('ok'=>true, 'status'=>$http['status'], 'text'=>$text, 'latency_ms'=>$latency);
    }

    /* ------------------------------------------------------- models + refresh */

    /**
     * Only the model list of a provider is reloaded (no key test, no text or image test): new models appear and withdrawn ones go.
     * Several providers publish their list without a key; verified image support of a model already known is kept.
     */
    public function refresh_models_only($id)
    {
        $providers = $this->providers();
        if( !isset($providers[$id]) || $providers[$id]['base_url'] === '' ) return array('ok'=>false, 'error'=>'Unknown provider.');
        $provider = $providers[$id];
        $listing = $this->fetch_models($provider, (string) $provider['api_key']);
        if( !$listing['ok'] ) return array('ok'=>false, 'status'=>$listing['status'], 'error'=>$listing['error']);
        $st = $this->get_state($id); $old = array();
        foreach( (array) json_decode($st && $st['models_json'] ? $st['models_json'] : '[]', true) as $m ) if( isset($m['id']) ) $old[$m['id']] = $m;
        $models = array();
        foreach( $listing['models'] as $m ) {
            if( isset($old[$m['id']]) && array_key_exists('vision', $old[$m['id']]) && $old[$m['id']]['vision'] !== null && $m['vision'] !== true ) { $m['vision'] = $old[$m['id']]['vision']; $m['score'] = $this->score_model($m) - ($m['vision'] === false ? 30 : 0); }
            $models[] = $m;
        }
        $this->save_state($id, array('models_json' => json_encode(array_slice($models, 0, 1500))));
        return array('ok'=>true, 'count'=>count($models), 'before'=>count($old));
    }

    /**
     * Full check of one provider: validate the key, load and rank the models,
     * detect the plan where the provider exposes it, ping the best model and
     * capture the quota headers. Saves and returns the provider's public state.
     */
    public function refresh($id, $key_override = '')
    {
        $providers = $this->providers();
        if( !isset($providers[$id]) ) return null;
        $provider = $providers[$id];
        $key = $key_override !== '' ? $key_override : $provider['api_key'];
        $now = date('Y-m-d H:i:s');
        @set_time_limit(240);
        $checks = array();
        $check = function($k, $ok, $label, $detail = '') use (&$checks) { $checks[] = array('k'=>$k, 'ok'=>$ok, 'label'=>$label, 'detail'=>(string) $detail); };
        if( $key === '' && empty($provider['key_optional']) ) {
            $check('key', false, 'No API key', 'Add a key from '.$provider['signup_url']);
            $this->save_state($id, array('status'=>'not_configured', 'message'=>'Add an API key to check this provider.', 'checked_at'=>$now, 'checks_json'=>json_encode(array('items'=>$checks, 'at'=>$now))));
            return $this->public_state($id);
        }

        if( $provider['base_url'] === '' ) {
            $check('key', false, 'No Base URL', 'Enter the service API address, e.g. https://…/v1');
            $this->save_state($id, array('status'=>'not_configured', 'message'=>'Add the Base URL.', 'checked_at'=>$now, 'checks_json'=>json_encode(array('items'=>$checks, 'at'=>$now))));
            return $this->public_state($id);
        }
        // 1) Key: the model list only loads with a valid key.
        $listing = $this->fetch_models($provider, $key);
        if( !$listing['ok'] ) {
            $st = $this->status_from_http($listing['status']);
            $check('key', false, $st === 'invalid_key' ? 'API key rejected' : 'Could not reach the provider', 'HTTP '.$listing['status'].' · '.$listing['error']);
            $this->save_state($id, array('status'=>$st, 'message'=>$listing['error'], 'checked_at'=>$now, 'checks_json'=>json_encode(array('items'=>$checks, 'at'=>$now))));
            return $this->public_state($id);
        }
        $check('key', true, 'API key accepted', 'Model list loaded');

        // 2) Models: every chat model is kept; image support is known or verified.
        $models = $this->probe_vision($provider, $key, $listing['models']);
        $nv = count(array_filter($models, function($m) { return $m['vision'] === true; }));
        $nf = count(array_filter($models, function($m) { return $m['free']; }));
        $check('models', count($models) > 0, count($models).' chat models', $nv.' read images · '.$nf.' free');

        // 3) Plan / account.
        $detected_plan = null; $account = null; $plan_detail = '';
        if( $id === 'openrouter' ) {
            $info = $this->http('GET', 'https://openrouter.ai/api/v1/key', array('Authorization: Bearer '.$key), null, 15);
            $data = $info['ok'] ? json_decode($info['body'], true) : null;
            if( isset($data['data']) && is_array($data['data']) ) {
                $account = $data['data'];
                $detected_plan = !empty($account['is_free_tier']) ? 'free' : 'paid';
                $plan_detail = 'Used $'.round((float) (isset($account['usage']) ? $account['usage'] : 0), 4).(isset($account['limit']) && $account['limit'] !== null ? ' of $'.$account['limit'] : '').' · free models only: '.($detected_plan === 'free' ? 'yes' : 'no');
            }
        }
        $plan = $provider['plan_setting'] !== 'auto' ? $provider['plan_setting'] : ($detected_plan ? $detected_plan : 'free');
        $provider['plan'] = $plan;
        $allowed = function($m) use ($provider, $plan) { return ($plan === 'paid' || $m['free']) && $this->model_allowed($provider, $m['id'], $plan); };

        // 4) Text test: best text models until one answers.
        $text_ranked = $models;
        usort($text_ranked, function($a, $b) { return $a['text_score'] == $b['text_score'] ? strcmp($a['id'], $b['id']) : ($a['text_score'] < $b['text_score'] ? 1 : -1); });
        if( !$provider['auto_model'] ) $cands = array($provider['model_setting']);
        else { $cands = array(); foreach( $text_ranked as $m ) { if( $allowed($m) ) $cands[] = $m['id']; if( count($cands) >= 3 ) break; } if( !$cands ) $cands = array($provider['default_model']); }
        $ping = array('ok'=>false, 'status'=>0, 'error'=>'No model to test.', 'latency_ms'=>null); $text_ok = ''; $tried = array();
        foreach( $cands as $model ) {
            // Reasoning models spend tokens thinking first, so leave room for the answer.
            $ping = $this->request($provider, $key, $model, 'Reply with exactly: OK', null, null, false, 400);
            $tried[$model] = $ping['ok'] ? 'ok '.$ping['latency_ms'].' ms' : mb_substr($ping['error'], 0, 160);
            if( $ping['ok'] ) { $text_ok = $model; break; }
            if( !empty($ping['zero_quota']) || in_array($ping['status'], array(401, 403), true) ) break;
        }
        $check('text', $text_ok !== '', $text_ok !== '' ? 'Text: '.$text_ok.' answered in '.$ping['latency_ms'].' ms' : 'Text test failed', $text_ok !== '' ? '' : $ping['error']);

        // Quota windows come back on the test response.
        $st = $this->get_state($id);
        $limits = $st && $st['limits_json'] ? json_decode($st['limits_json'], true) : array();
        if( !empty($ping['zero_quota']) ) { $check('plan', false, 'No usable plan (quota is 0)', !empty($provider['zero_quota_hint']) ? $provider['zero_quota_hint'] : 'Activate a plan on the provider dashboard.'); }
        elseif( !empty($limits['windows']) ) {
            $parts = array();
            foreach( $limits['windows'] as $name => $w ) $parts[] = $name.': '.(isset($w['remaining']) ? $w['remaining'] : '?').' / '.(isset($w['limit']) ? $w['limit'] : '?');
            $plan_detail = trim($plan_detail.' '.implode(' · ', $parts));
        }

        // Gemini: a Pro model answers only when billing is on — that tells free from paid.
        if( $id === 'gemini' && $provider['plan_setting'] === 'auto' && $text_ok !== '' ) {
            foreach( $models as $m ) {
                if( $m['free'] || !preg_match('/-pro/', $m['id']) || preg_match('/preview|exp/', $m['id']) ) continue;
                $pp = $this->request($provider, $key, $m['id'], 'Reply with exactly: OK', null, null, false, 400);
                $tried[$m['id'].' (plan check)'] = $pp['ok'] ? 'ok' : mb_substr($pp['error'], 0, 160);
                if( $pp['ok'] ) { $detected_plan = 'paid'; $plan_detail = 'Billing is on ('.$m['id'].' answered)'; break; }
                if( $pp['status'] === 429 || $pp['status'] === 403 || preg_match('/quota|billing|free.?tier/i', $pp['error']) ) { $detected_plan = 'free'; $plan_detail = 'Free tier — '.$m['id'].' needs billing'; break; }
            }
        }
        if( empty($ping['zero_quota']) ) $check('plan', $text_ok !== '' ? true : null, 'Plan: '.ucfirst($detected_plan ?: $plan).($provider['plan_setting'] === 'auto' ? ($detected_plan ? ' (detected)' : ' (assumed)') : ' (set by you)'), $plan_detail);
        if( $provider['plan_setting'] === 'auto' && $detected_plan ) { $plan = $detected_plan; $provider['plan'] = $plan; }

        // 5) Image test: the best image model must read a number from a picture.
        $vis_ok = ''; $vis_err = 'No image model available on this plan.';
        $vcands = array();
        if( !$provider['auto_model'] ) $vcands = array($provider['model_setting']);
        else foreach( $models as $m ) { if( $m['vision'] === true && $allowed($m) ) $vcands[] = $m['id']; if( count($vcands) >= 3 ) break; }
        if( !empty($ping['zero_quota']) ) { $vcands = array(); $vis_err = 'Skipped — no plan quota.'; }
        else {
            foreach( $vcands as $model ) {
                $r = $this->vision_test($provider, $key, $model);
                $tried[$model.' (image)'] = $r['ok'] ? 'ok '.$r['latency_ms'].' ms' : $r['error'];
                if( $r['ok'] ) { $vis_ok = $model; $vis_lat = $r['latency_ms']; break; }
                $vis_err = $r['error'];
                if( in_array($r['status'], array(401, 403), true) || !empty($r['zero_quota']) ) break;
            }
        }
        $check('vision', $vis_ok !== '' ? true : ($vcands ? false : null), $vis_ok !== '' ? 'Images: '.$vis_ok.' read the test picture in '.$vis_lat.' ms' : 'Image test failed', $vis_ok !== '' ? '' : $vis_err);

        $best_free = ''; $best_paid = '';
        foreach( $models as $m ) {
            if( $m['vision'] !== true ) continue;
            if( $best_paid === '' ) $best_paid = $m['id'];
            if( $best_free === '' && $m['free'] ) $best_free = $m['id'];
        }
        if( $vis_ok !== '' && $provider['auto_model'] ) { if( $plan === 'paid' ) $best_paid = $vis_ok; else $best_free = $vis_ok; }
        $working = $vis_ok !== '' ? $vis_ok : $text_ok;
        $state = array(
            'status' => $working !== '' ? 'ok' : (!empty($ping['zero_quota']) ? 'no_quota' : $this->status_from_http($ping['status'])),
            'message' => $working !== '' ? 'Connected · text: '.($text_ok ?: '—').' · images: '.($vis_ok ?: '—') : 'Model test failed: '.$ping['error'],
            'latency_ms' => $ping['latency_ms'],
            'checked_at' => $now,
            'detected_plan' => $detected_plan,
            'models_json' => json_encode(array_slice($models, 0, 1500)),
            'best_free' => $best_free,
            'best_paid' => $best_paid,
            'checks_json' => json_encode(array('items'=>$checks, 'tried'=>$tried, 'text_model'=>$text_ok, 'vision_model'=>$vis_ok, 'at'=>$now)),
        );
        if( $account !== null ) $state['account_json'] = json_encode($account);
        $this->save_state($id, $state);
        return $this->public_state($id);
    }

    /** Sends a picture with a random number; ok only when the model reads it back. */
    public function vision_test($provider, $key, $model)
    {
        $digits = (string) mt_rand(1000, 9999);
        $im = imagecreatetruecolor(240, 120);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagettftext($im, 48, 0, 20, 90, imagecolorallocate($im, 0, 0, 0), __DIR__.'/DejaVuSans-Bold.ttf', $digits);
        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        $r = $this->request($provider, $key, $model, 'What number is written in this image? Reply with the digits only.', $png, 'image/png', false, 300);
        if( !$r['ok'] ) return $r;
        if( strpos(preg_replace('/\D+/', '', $r['text']), $digits) === false ) return array('ok'=>false, 'status'=>200, 'error'=>$model.' could not read the test picture.', 'latency_ms'=>$r['latency_ms']);
        return $r;
    }

    /** Fetch and rank a provider's chat models (image support flagged, not filtered). */
    public function fetch_models($provider, $api_key, $public_pass = false)
    {
        if( !$public_pass && $api_key !== '' && !empty($provider['models_public']) ) { // the whole catalogue (free and paid) next to what the key itself lists
            $own = $this->fetch_models($provider, $api_key, true); $all = $this->fetch_models($provider, '', true);
            if( !$all['ok'] ) return $own;
            if( !$own['ok'] ) return $all;
            $have = array(); foreach( $own['models'] as $m ) $have[$m['id']] = true;
            foreach( $all['models'] as $m ) if( !isset($have[$m['id']]) ) { if( $m['note'] === '' ) $m['note'] = 'not in your key\'s list'; $own['models'][] = $m; }
            usort($own['models'], function($a, $b) { if( $a['score'] == $b['score'] ) return strcmp($a['id'], $b['id']); return $a['score'] < $b['score'] ? 1 : -1; });
            return $own;
        }
        if( $provider['api'] === 'gemini' ) {
            $http = $this->http('GET', rtrim($provider['base_url'], '/').'/models?pageSize=1000', array('x-goog-api-key: '.$api_key), null, 20);
        } else {
            $url = isset($provider['models_url']) ? $provider['models_url'] : rtrim($provider['base_url'], '/').'/models';
            $http = $this->http('GET', $url, array_merge(array('Accept: application/json'), $api_key !== '' ? array('Authorization: Bearer '.$api_key) : array()), null, 20);
        }
        if( !$http['ok'] ) return array('ok'=>false, 'status'=>$http['status'], 'error'=>$http['error']);
        $data = json_decode($http['body'], true);
        if( !is_array($data) ) return array('ok'=>false, 'status'=>$http['status'], 'error'=>'Model list is not valid JSON.');
        $rows = isset($data['models']) ? $data['models'] : (isset($data['data']) ? $data['data'] : array());

        $models = array();
        foreach( $rows as $row ) {
            if( !is_array($row) ) continue;
            $meta = $this->model_meta($provider['id'], $row);
            if( !$meta || isset($models[$meta['id']]) ) continue;
            $meta['score'] = $this->score_model($meta) - ($meta['vision'] === false ? 30 : 0);
            $meta['text_score'] = $this->score_model($meta);
            $models[$meta['id']] = $meta;
        }
        $models = array_values($models);
        usort($models, function($a, $b) {
            if( $a['score'] == $b['score'] ) return strcmp($a['id'], $b['id']);
            return $a['score'] < $b['score'] ? 1 : -1;
        });
        return array('ok'=>true, 'status'=>$http['status'], 'models'=>$models);
    }

    /** Normalise one model row: id, name, vision, free, context, note. Null for rows that are not chat models. */
    protected function model_meta($provider_id, $row)
    {
        $id = isset($row['id']) ? $row['id'] : (isset($row['name']) ? $row['name'] : '');
        $id = preg_replace('#^models/#', '', (string) $id);
        if( $id === '' ) return null;
        $n = strtolower($id);
        $meta = array('id'=>$id, 'name'=>$id, 'vision'=>false, 'free'=>true, 'context'=>null, 'note'=>'', 'deprecated'=>false);
        $catalog = self::catalog();
        $format = isset($catalog[$provider_id]['models_format']) ? $catalog[$provider_id]['models_format'] : $provider_id;
        switch( $format ) {
            case 'gemini':
                if( isset($row['supportedGenerationMethods']) && !in_array('generateContent', $row['supportedGenerationMethods'], true) ) return null;
                if( preg_match('/embedding|aqa|imagen|veo|tts|audio|live|image|robotics|computer-use|learnlm|lyria|transcrib|gemini-1\.0|-vision$/', $n) ) return null;
                $meta['vision'] = (bool) preg_match('/gemini|gemma-3/', $n) && !preg_match('/gemma-3n?-e?1b/', $n);
                $meta['free'] = !preg_match('/-pro\b|-pro-|-ultra/', $n);
                if( !$meta['free'] ) $meta['note'] = 'billing usually required';
                $meta['name'] = isset($row['displayName']) ? $row['displayName'] : $id;
                $meta['context'] = isset($row['inputTokenLimit']) ? (int) $row['inputTokenLimit'] : null;
                break;
            case 'groq':
                if( isset($row['active']) && !$row['active'] ) return null;
                if( preg_match('/whisper|guard|tts|playai|orpheus|prompt|compound|safeguard/', $n) ) return null;
                $meta['vision'] = preg_match('/llama-4|vision|[-_]vl\b|[-_]vl-|llava|maverick|scout/', $n) ? true : null;
                $meta['context'] = isset($row['context_window']) ? (int) $row['context_window'] : null;
                break;
            case 'mistral':
                $caps = isset($row['capabilities']) && is_array($row['capabilities']) ? $row['capabilities'] : array();
                if( isset($caps['completion_chat']) && !$caps['completion_chat'] ) return null;
                if( preg_match('/ocr|embed|moderation|voxtral|codestral|devstral|vibe|leanstral|^labs-/', $n) ) return null;
                $meta['vision'] = !empty($caps['vision']);
                $meta['deprecated'] = !empty($row['deprecation']);
                $meta['context'] = isset($row['max_context_length']) ? (int) $row['max_context_length'] : null;
                $meta['name'] = isset($row['name']) && $row['name'] ? $row['name'] : $id;
                break;
            case 'openrouter':
                $mods = isset($row['architecture']['input_modalities']) ? (array) $row['architecture']['input_modalities'] : array();
                $meta['vision'] = in_array('image', $mods, true);
                $prompt_price = isset($row['pricing']['prompt']) ? (float) $row['pricing']['prompt'] : 1;
                $completion_price = isset($row['pricing']['completion']) ? (float) $row['pricing']['completion'] : 1;
                $meta['free'] = substr($id, -5) === ':free' || ($prompt_price == 0 && $completion_price == 0 && strpos($id, 'openrouter/') !== 0);
                if( !$meta['free'] ) $meta['note'] = '$'.rtrim(rtrim(number_format($prompt_price * 1000000, 2, '.', ''), '0'), '.').' / 1M input tokens';
                $meta['context'] = isset($row['context_length']) ? (int) $row['context_length'] : null;
                $meta['name'] = isset($row['name']) ? $row['name'] : $id;
                break;
        }
        if( !empty($catalog[$provider_id]['skip_models']) && preg_match($catalog[$provider_id]['skip_models'], $n) ) { $meta['avoid'] = true; $meta['note'] = 'other API route or experimental — never picked automatically'; } // listed, but not chosen by itself
        if( !empty($catalog[$provider_id]['free_pattern']) ) {
            $meta['free'] = (bool) preg_match($catalog[$provider_id]['free_pattern'], $id);
            if( !$meta['free'] && $meta['note'] === '' ) $meta['note'] = 'credit required';
        }
        return $meta;
    }

    /** Heuristic quality score for reading receipts: model family first, then version, size and stability. */
    public function score_model($meta)
    {
        $n = strtolower($meta['id']);
        $rules = array(
            '/gpt-oss-120b/' => 80, '/gpt-oss-20b/' => 66, '/kimi-k2/' => 82, '/deepseek[\w.-]*(r1|v3|chat)/' => 82,
            '/llama-3\.3-70b/' => 72, '/llama-3\.1-8b/' => 48, '/qwen3[\w.-]*(235|480)b/' => 80, '/qwen3[\w.-]*32b/' => 74, '/glm-4\.[5-9]/' => 78,
            '/gpt-5[\w.]*-nano/' => 62, '/gpt-5[\w.]*-mini/' => 86, '/gpt-5/' => 94,
            '/gpt-4\.1-nano/' => 60, '/gpt-4\.1-mini/' => 80, '/gpt-4\.1/' => 88,
            '/gpt-4o-mini/' => 72, '/gpt-4o/' => 84, '/\bo[34](-mini)?\b/' => 70,
            '/claude[\w.-]*opus/' => 93, '/claude[\w.-]*sonnet/' => 91, '/claude[\w.-]*haiku/' => 78,
            '/gemini-[\d.]+-pro/' => 86, '/gemini-[\d.]+-flash-lite/' => 70, '/gemini-[\d.]+-flash/' => 80, '/gemini/' => 64,
            '/grok-4/' => 88, '/grok/' => 76,
            '/llama-4-maverick/' => 78, '/llama-4-scout/' => 72, '/llama-4/' => 70,
            '/qwen[\w.-]*vl[\w.-]*(72|235|32)b/' => 76, '/qwen[\w.-]*vl/' => 64, '/qwen3\.[5-9]/' => 74,
            '/mistral-large|pixtral-large/' => 82, '/mistral-medium/' => 80, '/mistral-small/' => 72,
            '/ministral-14b/' => 68, '/pixtral-12b/' => 62, '/ministral-8b/' => 58, '/ministral-3b/' => 50,
            '/gemma-3n/' => 48, '/gemma-3-27b/' => 64, '/gemma-3-12b/' => 56, '/gemma-3/' => 50, '/gemma/' => 45,
            '/llama-3\.2-90b-vision/' => 66, '/llama-3\.2-11b-vision/' => 54,
            '/nova-pro/' => 74, '/nova-lite/' => 64, '/phi-4-multimodal/' => 52,
        );
        $score = null;
        foreach( $rules as $pattern => $value ) {
            if( preg_match($pattern, $n) ) { $score = $value; break; }
        }
        if( $score === null ) {
            $score = 40;
            if( preg_match('/(\d+)b\b/', $n, $m) ) $score += min(25, (int) floor((int) $m[1] / 4));
        }
        if( preg_match('/gemini-(\d+(?:\.\d+)?)/', $n, $m) ) $score += (float) $m[1] * 8;
        if( preg_match('/preview|-exp|experimental|beta/', $n) ) $score -= 3;
        if( preg_match('/customtools|-tuning|thinking-exp/', $n) ) $score -= 5;
        if( preg_match('/-latest$/', $n) ) $score += 2;
        if( preg_match('/-\d{4}(-\d{2}-\d{2})?$|-\d{3}$/', $n) ) $score -= 1;
        if( !empty($meta['deprecated']) ) $score -= 60;
        return round($score, 1);
    }

    /* ---------------------------------------------------------- state + usage */

    /** True when the provider says the limit itself is 0 (no plan), not that a window is used up. */
    protected function zero_quota($headers)
    {
        foreach( $headers as $name => $value ) {
            if( strpos($name, 'x-ratelimit-limit-') === 0 && trim($value) === '0' ) return true;
        }
        return false;
    }

    /**
     * For providers whose model list does not say which models accept images (Groq),
     * send each unknown model a small image with a number and keep the ones that read it.
     * Results are cached for 7 days.
     */
    protected function probe_vision($provider, $key, $models)
    {
        $state = $this->get_state($provider['id']);
        $cache = $state && !empty($state['probe_json']) ? json_decode($state['probe_json'], true) : array();
        if( !is_array($cache) ) $cache = array();
        $changed = false;
        $png = null; $digits = ''; $probes = 0;
        foreach( $models as $i => $m ) {
            if( $m['vision'] !== null ) continue;
            if( empty($provider['probe_vision']) || $probes >= 6 ) { $models[$i]['vision'] = false; continue; }
            $probes++;
            $cached = isset($cache[$m['id']]) ? $cache[$m['id']] : null;
            if( $cached && strtotime($cached['at']) > time() - 7 * 86400 ) { $models[$i]['vision'] = (bool) $cached['vision']; continue; }
            if( $png === null ) {
                $digits = (string) mt_rand(1000, 9999);
                $im = imagecreatetruecolor(240, 120);
                imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
                imagettftext($im, 48, 0, 20, 90, imagecolorallocate($im, 0, 0, 0), __DIR__.'/DejaVuSans-Bold.ttf', $digits);
                ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
            }
            $r = $this->request($provider, $key, $m['id'], 'What number is written in this image? Reply with the digits only.', $png, 'image/png', false, 300);
            if( !$r['ok'] && in_array($r['status'], array(0, 429, 500, 502, 503, 504), true) ) { $models[$i]['vision'] = false; continue; } // unknown: retry next refresh
            $vision = $r['ok'] && strpos(preg_replace('/\D+/', '', $r['text']), $digits) !== false;
            $cache[$m['id']] = array('vision' => $vision, 'at' => date('Y-m-d H:i:s'));
            $models[$i]['vision'] = $vision;
            if( $vision ) $models[$i]['note'] = 'image support verified';
            $changed = true;
        }
        if( $changed ) $this->save_state($provider['id'], array('probe_json' => json_encode($cache)));
        // Image models first (receipts), then text-only models.
        $models = array_values($models);
        usort($models, function($a, $b) { return $a['score'] == $b['score'] ? strcmp($a['id'], $b['id']) : ($a['score'] < $b['score'] ? 1 : -1); });
        return $models;
    }

    public function ensure_tables()
    {
        if( $this->tables_ready ) return;
        $this->tables_ready = true;
        $db = wi_db(); if( !$db ) return;
        $db->query("CREATE TABLE IF NOT EXISTS `wi_ai_state` (
            `provider` VARCHAR(30) NOT NULL, `status` VARCHAR(30) DEFAULT NULL, `message` VARCHAR(500) DEFAULT NULL, `latency_ms` INT DEFAULT NULL,
            `checked_at` DATETIME DEFAULT NULL, `last_used_at` DATETIME DEFAULT NULL, `last_model` VARCHAR(160) DEFAULT NULL, `detected_plan` VARCHAR(10) DEFAULT NULL,
            `best_free` VARCHAR(160) DEFAULT NULL, `best_paid` VARCHAR(160) DEFAULT NULL, `models_json` MEDIUMTEXT, `limits_json` TEXT, `account_json` TEXT,
            `probe_json` TEXT, `checks_json` TEXT, `updated_at` DATETIME DEFAULT NULL, PRIMARY KEY (`provider`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS `wi_ai_usage` (
            `provider` VARCHAR(30) NOT NULL, `day` DATE NOT NULL, `requests` INT NOT NULL DEFAULT 0, `ok` INT NOT NULL DEFAULT 0, `errors` INT NOT NULL DEFAULT 0,
            `rate_limited` INT NOT NULL DEFAULT 0, `tokens_in` BIGINT NOT NULL DEFAULT 0, `tokens_out` BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (`provider`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function get_state($id)
    {
        if( array_key_exists($id, $this->state_cache) ) return $this->state_cache[$id];
        $this->ensure_tables(); $db = wi_db(); $row = null;
        if( $db ) { $k = $this->sk($id); $st = $db->prepare('SELECT * FROM `wi_ai_state` WHERE `provider` = ?'); $st->bind_param('s', $k); $st->execute(); $row = $st->get_result()->fetch_assoc(); }
        return $this->state_cache[$id] = $row ? $row : null;
    }

    public function save_state($id, $data)
    {
        $this->ensure_tables(); $db = wi_db(); if( !$db ) return;
        $data['updated_at'] = date('Y-m-d H:i:s'); $data['provider'] = $this->sk($id);
        $cols = array_keys($data); $q = function($c) { return '`'.str_replace('`', '', $c).'`'; };
        $sql = 'INSERT INTO `wi_ai_state` ('.implode(',', array_map($q, $cols)).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).') ON DUPLICATE KEY UPDATE '
            .implode(',', array_map(function($c) use ($q) { return $q($c).'=VALUES('.$q($c).')'; }, array_diff($cols, array('provider'))));
        $vals = array_map(function($v) { return $v === null ? null : (is_scalar($v) ? (string) $v : json_encode($v)); }, array_values($data));
        $st = $db->prepare($sql); $st->bind_param(str_repeat('s', count($vals)), ...$vals); $st->execute();
        unset($this->state_cache[$id]);
    }

    protected function record_usage($id, $ok, $status, $tokens_in, $tokens_out)
    {
        $this->ensure_tables(); $db = wi_db(); if( !$db ) return;
        $st = $db->prepare('INSERT INTO `wi_ai_usage` (`provider`,`day`,`requests`,`ok`,`errors`,`rate_limited`,`tokens_in`,`tokens_out`) VALUES (?,?,1,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE `requests`=`requests`+1, `ok`=`ok`+VALUES(`ok`), `errors`=`errors`+VALUES(`errors`), `rate_limited`=`rate_limited`+VALUES(`rate_limited`), `tokens_in`=`tokens_in`+VALUES(`tokens_in`), `tokens_out`=`tokens_out`+VALUES(`tokens_out`)');
        $id = $this->sk($id); $day = date('Y-m-d'); $o = $ok ? 1 : 0; $e = $ok ? 0 : 1; $r = $status === 429 ? 1 : 0; $ti = (int) $tokens_in; $to = (int) $tokens_out;
        $st->bind_param('ssiiiii', $id, $day, $o, $e, $r, $ti, $to); $st->execute();
    }

    /** The provider's own limits for one model: array(per minute, per day), or null when none are known. */
    public function model_limit($provider, $model)
    {
        foreach( (array) (isset($provider['model_limits']) ? $provider['model_limits'] : array()) as $re => $lim ) if( preg_match($re, $model) ) return $lim;
        return null;
    }

    /** Name a model's counters are kept under: with several keys (each its own Google project) every key has its own limits. */
    public function use_key($provider, $model)
    {
        return count((array) ($provider['api_keys'] ?? array())) > 1 ? $model.'#'.substr(md5((string) $provider['api_key']), 0, 10) : $model;
    }

    /** True when this model already used its free requests for this minute or this day (days reset at midnight Pacific time). */
    public function model_over_limit($provider, $model, $limits)
    {
        if( ($provider['plan'] ?? 'free') === 'paid' || !($lim = $this->model_limit($provider, $model)) ) return false;
        if( (int) $lim[1] <= 0 ) return true;
        $k = $this->use_key($provider, $model); $u = isset($limits['model_use'][$k]) ? $limits['model_use'][$k] : array();
        $day = (new DateTime('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
        if( ($u['d'] ?? '') === $day && (int) ($u['n'] ?? 0) >= (int) $lim[1] ) return true;
        return count(array_filter((array) ($u['t'] ?? array()), function($t) { return $t > time() - 60; })) >= (int) $lim[0];
    }

    /** Status and quota windows from a live response. */
    protected function record_status($provider, $http, $latency, $model)
    {
        $state = $this->get_state($provider['id']);
        $limits = $state && $state['limits_json'] ? json_decode($state['limits_json'], true) : array();
        if( !is_array($limits) ) $limits = array();
        if( $this->model_limit($provider, $model) ) { // every request counts against the model's limit, answered or not
            $day = (new DateTime('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d'); $mk = $this->use_key($provider, $model); $u = isset($limits['model_use'][$mk]) ? $limits['model_use'][$mk] : array();
            if( ($u['d'] ?? '') !== $day ) $u = array('d' => $day, 'n' => 0, 't' => array());
            $u['n'] = (int) $u['n'] + 1; $u['t'] = array_values(array_filter((array) $u['t'], function($t) { return $t > time() - 60; })); $u['t'][] = time();
            $limits['model_use'][$mk] = $u;
        }
        $windows = $this->parse_limit_headers($this->last_headers);
        if( $windows ) $limits['windows'] = $windows;
        if( isset($this->last_headers['retry-after']) ) $limits['retry_after'] = $this->last_headers['retry-after'];
        if( !empty($http['quota']) ) $limits['quota_hit'] = $http['quota'];
        elseif( $http['ok'] ) unset($limits['quota_hit'], $limits['retry_after']);
        $limits['captured_at'] = date('Y-m-d H:i:s');
        // Gemini and OpenRouter limit each model separately: a 429 cools down that model only, not the provider.
        $per_model = $provider['api'] === 'gemini' || !empty($provider['per_model_limits']);
        if( !$http['ok'] && $http['status'] === 429 && empty($http['zero_quota']) && $per_model ) {
            $wait = 60;
            if( !empty($http['quota']['retry_delay']) ) $wait = max($wait, $this->duration_seconds($http['quota']['retry_delay']));
            foreach( (array) (isset($http['quota']['violations']) ? $http['quota']['violations'] : array()) as $v ) {
                if( stripos((string) (isset($v['quota']) ? $v['quota'] : ''), 'PerDay') !== false ) { $reset = new DateTime('tomorrow', new DateTimeZone('America/Los_Angeles')); $wait = max($wait, $reset->getTimestamp() - time()); }
            }
            $limits['model_cooldown'][$this->use_key($provider, $model)] = time() + $wait;
            unset($limits['quota_hit'], $limits['retry_after']);
            $this->save_state($provider['id'], array('limits_json'=>json_encode($limits), 'last_used_at'=>date('Y-m-d H:i:s'), 'message'=>$model.': '.$http['error']));
            return;
        }
        if( !empty($limits['model_cooldown']) ) $limits['model_cooldown'] = array_filter($limits['model_cooldown'], function($t) { return $t > time(); });
        $data = array(
            'status' => $http['ok'] ? 'ok' : (!empty($http['zero_quota']) ? 'no_quota' : $this->status_from_http($http['status'])),
            'message' => $http['ok'] ? 'Last request succeeded ('.$model.').' : $http['error'],
            'latency_ms' => $latency,
            'last_used_at' => date('Y-m-d H:i:s'),
            'last_model' => $model,
            'limits_json' => json_encode($limits),
        );
        if( !empty($http['quota']['free_tier']) && (!$state || !$state['detected_plan']) ) $data['detected_plan'] = 'free';
        $this->save_state($provider['id'], $data);
    }

    protected function parse_limit_headers($headers)
    {
        $windows = array();
        foreach( $headers as $name => $value ) {
            if( preg_match('/^x-ratelimit-(limit|remaining|reset)-(.+)$/', $name, $m) ) {
                $windows[$m[2]][$m[1]] = $value;
            }
        }
        ksort($windows);
        return $windows;
    }

    public function status_from_http($status)
    {
        if( $status === 401 || $status === 403 ) return 'invalid_key';
        if( $status === 429 ) return 'rate_limited';
        if( $status === 402 ) return 'no_quota';
        return 'error';
    }

    public function usage_summary($id)
    {
        $this->ensure_tables(); $db = wi_db();
        $empty = array('requests'=>0, 'ok'=>0, 'errors'=>0, 'rate_limited'=>0, 'tokens_in'=>0, 'tokens_out'=>0);
        if( !$db ) return array('today'=>$empty, 'month'=>$empty);
        $id = $this->sk($id); $st = $db->prepare('SELECT * FROM `wi_ai_usage` WHERE `provider` = ? AND `day` = ?'); $d = date('Y-m-d'); $st->bind_param('ss', $id, $d); $st->execute(); $today = $st->get_result()->fetch_assoc();
        $st = $db->prepare('SELECT COALESCE(SUM(requests),0) requests, COALESCE(SUM(ok),0) ok, COALESCE(SUM(errors),0) errors, COALESCE(SUM(rate_limited),0) rate_limited, COALESCE(SUM(tokens_in),0) tokens_in, COALESCE(SUM(tokens_out),0) tokens_out FROM `wi_ai_usage` WHERE `provider` = ? AND `day` >= ?');
        $m1 = date('Y-m-01'); $st->bind_param('ss', $id, $m1); $st->execute(); $month = $st->get_result()->fetch_assoc();
        $cast = function($row) use ($empty) { $out = $empty; foreach( $empty as $k => $v ) $out[$k] = isset($row[$k]) ? (int) $row[$k] : 0; return $out; };
        return array('today'=>$cast($today), 'month'=>$cast($month));
    }

    /** Everything the settings page shows for one provider. Never includes the key. */
    public function public_state($id)
    {
        unset($this->state_cache[$id]);
        $providers = $this->providers();
        if( !isset($providers[$id]) ) return null;
        $p = $providers[$id];
        $state = $this->get_state($id);
        $models = $state && $state['models_json'] ? json_decode($state['models_json'], true) : array();
        $limits = $state && $state['limits_json'] ? json_decode($state['limits_json'], true) : array();
        $account = $state && $state['account_json'] ? json_decode($state['account_json'], true) : null;
        $windows = array();
        if( !empty($limits['windows']) ) {
            foreach( $limits['windows'] as $name => $w ) {
                $label = isset($p['limit_labels'][$name]) ? $p['limit_labels'][$name] : ucfirst(str_replace(array('-', '_', 'req '), array(' / ', ' ', 'requests '), $name));
                $windows[] = array('label'=>$label, 'limit'=>isset($w['limit']) ? $w['limit'] : null, 'remaining'=>isset($w['remaining']) ? $w['remaining'] : null, 'reset'=>isset($w['reset']) ? $w['reset'] : null);
            }
        }
        $status = $state && $state['status'] ? $state['status'] : ($p['has_key'] || !empty($p['key_optional']) ? 'unchecked' : 'not_configured');
        $skip = $this->skip_reason($p);
        $health = $this->health($id);
        return array(
            'health' => $health,
            'name' => $p['name'],
            'base_url' => !empty($p['custom']) ? $p['base_url'] : null,
            'custom' => !empty($p['custom']),
            'checks' => $state && !empty($state['checks_json']) ? json_decode($state['checks_json'], true) : null,
            'id' => $id,
            'enabled' => $p['enabled'],
            'has_key' => $p['has_key'],
            'key_optional' => !empty($p['key_optional']),
            'extra_keys' => (int) ($p['extra_keys'] ?? 0),
            'plan' => $p['plan'],
            'plan_setting' => $p['plan_setting'],
            'detected_plan' => $state ? $state['detected_plan'] : null,
            'model' => $p['model'],
            'model_setting' => $p['model_setting'],
            'default_model' => $p['default_model'],
            'best_free' => $state ? $state['best_free'] : null,
            'best_paid' => $state ? $state['best_paid'] : null,
            'models' => $models ? $models : array(),
            'status' => $status,
            'message' => $state ? $state['message'] : '',
            'latency_ms' => $state ? $state['latency_ms'] : null,
            'checked_at' => $state ? $state['checked_at'] : null,
            'last_used_at' => $state ? $state['last_used_at'] : null,
            'last_model' => $state ? $state['last_model'] : null,
            'windows' => $windows,
            'cooldowns' => isset($limits['model_cooldown']) ? array_filter((array) $limits['model_cooldown'], function($t) { return $t > time(); }) : array(),
            'quota_hit' => isset($limits['quota_hit']) ? $limits['quota_hit'] : null,
            'retry_after' => isset($limits['retry_after']) ? $limits['retry_after'] : null,
            'limits_captured_at' => isset($limits['captured_at']) ? $limits['captured_at'] : null,
            'account' => $account,
            'usage' => $this->usage_summary($id),
            'daily_limit' => $p['daily_limit'],
            'active' => $skip === '',
            'skip_reason' => $skip,
            'usage_url' => $p['usage_url'],
        );
    }

    /* --------------------------------------------------------------- helpers */

    public function parse_json($text)
    {
        $text = trim((string) $text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $json = json_decode($text, true);
        if( is_array($json) ) return $json;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if( $start !== false && $end !== false && $end > $start ) {
            $json = json_decode(substr($text, $start, $end - $start + 1), true);
            if( is_array($json) ) return $json;
        }
        return null;
    }

    /** Keys are stored encrypted with this installation's own key (.data/ai.key, AES-256-GCM). */
    protected function secret_key()
    {
        // The website and the background jobs may run as different system users: both must read it (a key that cannot be read
        // would silently encrypt with an empty key that the other side cannot open).
        $f = WI_DIR.'/ai.key';
        if( !is_file($f) ) { file_put_contents($f, base64_encode(random_bytes(32))); @chmod($f, 0644); }
        $k = base64_decode(trim((string) @file_get_contents($f)));
        if( strlen($k) !== 32 ) throw new RuntimeException('The AI key file cannot be read (.data/ai.key permissions).');
        return $k;
    }
    public function encrypt($value)
    {
        if( $value === '' ) return '';
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($value, 'aes-256-gcm', $this->secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:'.base64_encode($iv.$tag.$ct);
    }

    public function decrypt($value)
    {
        $value = (string) $value;
        if( strpos($value, 'v1:') !== 0 ) return '';
        $raw = base64_decode(substr($value, 3)); if( strlen($raw) < 29 ) return '';
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if( $plain === false ) $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', '', OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)); // saved while the key file could not be read
        return $plain === false ? '' : trim((string) $plain);
    }

    protected function http($method, $url, $headers, $body, $timeout)
    {
        $collected = array();
        $ch = curl_init($url);
        $options = array(
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$collected) {
                $parts = explode(':', $line, 2);
                if( count($parts) == 2 ) $collected[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        );
        if( $method === 'POST' ) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        if( $this->multi !== null && \Fiber::getCurrent() !== null ) {
            // Parallel mode: hand the transfer to the shared curl_multi loop and wait.
            curl_multi_add_handle($this->multi['mh'], $ch);
            $this->multi['wait'][spl_object_id($ch)] = \Fiber::getCurrent();
            $done = \Fiber::suspend();
            $response = $done['result'] === CURLE_OK ? curl_multi_getcontent($ch) : false;
            $curl_error = $done['result'] === CURLE_OK ? '' : curl_strerror($done['result']);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($this->multi['mh'], $ch);
        } else {
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
        }
        curl_close($ch);
        $this->last_headers = $collected;
        if( $response === false ) return array('ok'=>false, 'status'=>0, 'body'=>'', 'error'=>'Connection failed: '.$curl_error);
        if( $status < 200 || $status >= 300 ) {
            $decoded = json_decode($response, true);
            $message = '';
            if( isset($decoded['error']['message']) ) $message = $decoded['error']['message'];
            elseif( isset($decoded['error']) && is_string($decoded['error']) ) $message = $decoded['error'];
            elseif( isset($decoded['message']) && is_string($decoded['message']) ) $message = $decoded['message'];
            $quota = $status === 429 ? $this->gemini_quota($decoded) : null;
            if( $status === 429 ) $message = 'Free-tier rate limit reached. '.$message;
            return array('ok'=>false, 'status'=>$status, 'body'=>$response, 'quota'=>$quota, 'error'=>trim('HTTP '.$status.' '.mb_substr((string) $message, 0, 220)));
        }
        return array('ok'=>true, 'status'=>$status, 'body'=>$response);
    }

    /** Google reports the exhausted quota (and its limit) inside 429 error details. */
    protected function gemini_quota($decoded)
    {
        if( !isset($decoded['error']['details']) || !is_array($decoded['error']['details']) ) return null;
        $out = array('violations'=>array(), 'retry_delay'=>null, 'free_tier'=>false);
        foreach( $decoded['error']['details'] as $detail ) {
            if( isset($detail['violations']) && is_array($detail['violations']) ) {
                foreach( $detail['violations'] as $v ) {
                    $id = isset($v['quotaId']) ? $v['quotaId'] : (isset($v['quotaMetric']) ? $v['quotaMetric'] : '');
                    if( stripos($id, 'FreeTier') !== false ) $out['free_tier'] = true;
                    $out['violations'][] = array(
                        'quota' => $id,
                        'limit' => isset($v['quotaValue']) ? $v['quotaValue'] : null,
                        'model' => isset($v['quotaDimensions']['model']) ? $v['quotaDimensions']['model'] : null,
                    );
                }
            }
            if( isset($detail['retryDelay']) ) $out['retry_delay'] = $detail['retryDelay'];
        }
        return $out['violations'] || $out['retry_delay'] ? $out : null;
    }
}
