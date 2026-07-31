<?php
/**
 * Plugin Name: WP Rocket Cache Status Monitor
 * Description: Painel para acompanhar o status do preload do WP Rocket (pending, in-progress, completed, failed): filtro por tipo de conteúdo, paginação, ações diretas (recarregar/remover URL, recarregar tudo), diagnóstico de causa de falha (checagem HTTP + saúde do cron) e cache de classificação para performance. Genérico, sem dependências de projeto específico.
 * Version: 1.2.0
 * Author: Marcio Yamashita
 * Text Domain: wprsm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Bloqueia acesso direto.
}

class WPRSM_Status_Monitor {

	const CAPABILITY      = 'manage_options';
	const PAGE_SLUG        = 'wprsm-cache-status';
	const PER_PAGE          = 50;
	// Limite de linhas lidas do banco por carregamento de página. A classificação por
	// tipo é feita em PHP, então esse teto evita processar tabelas gigantes de uma vez.
	const FETCH_CAP         = 5000;
	// Quanto tempo (segundos) uma URL pode ficar em pending/in-progress antes de ser
	// sinalizada como "provavelmente travada".
	const STUCK_THRESHOLD   = 900; // 15 minutos.
	// Cache de classificação por URL (evita reprocessar url_to_postid/regex a cada carregamento).
	const TYPE_CACHE_KEY    = 'wprsm_url_type_cache';
	const TYPE_CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	const TYPE_CACHE_MAX    = 20000;
	// Hook de cron que o WP Rocket usa para processar a fila de preload.
	const PRELOAD_CRON_HOOK = 'rocket_preload_process_pending';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_wprsm_reload_url', array( $this, 'handle_reload_url' ) );
		add_action( 'admin_post_wprsm_remove_url', array( $this, 'handle_remove_url' ) );
		add_action( 'admin_post_wprsm_reload_all', array( $this, 'handle_reload_all' ) );
		add_action( 'admin_post_wprsm_run_cron_now', array( $this, 'handle_run_cron_now' ) );
		add_action( 'admin_post_wprsm_generate_cache', array( $this, 'handle_generate_cache' ) );
	}

	/**
	 * Registra a página no admin, dentro do menu do WP Rocket se ele existir,
	 * senão cria um menu próprio.
	 */
	public function register_admin_page() {
		if ( menu_page_url( 'wprocket', false ) ) {
			add_submenu_page(
				'wprocket',
				__( 'Status do Cache', 'wprsm' ),
				__( 'Status do Cache', 'wprsm' ),
				self::CAPABILITY,
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		} else {
			add_menu_page(
				__( 'Status do Cache (WP Rocket)', 'wprsm' ),
				__( 'Cache Status', 'wprsm' ),
				self::CAPABILITY,
				self::PAGE_SLUG,
				array( $this, 'render_page' ),
				'dashicons-performance',
				80
			);
		}
	}

	/**
	 * Retorna o nome da tabela de preload do WP Rocket.
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wpr_rocket_cache';
	}

	/**
	 * Verifica se a tabela existe.
	 */
	private function table_exists() {
		global $wpdb;
		$table = $this->get_table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * Monta a URL atual (com todos os filtros/paginação) para usar como "voltar para cá"
	 * depois de uma ação (reload/remove/reload all).
	 */
	private function current_filtered_url() {
		$base_url = menu_page_url( self::PAGE_SLUG, false );
		$args     = array();

		foreach ( array( 'wprsm_status', 'wprsm_type', 'wprsm_paged', 'wprsm_orderby', 'wprsm_order' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		return $args ? add_query_arg( $args, $base_url ) : $base_url;
	}

	// -----------------------------------------------------------------
	// AÇÕES DIRETAS
	// -----------------------------------------------------------------

	/**
	 * Recoloca uma única URL na fila de preload (status volta para "pending").
	 */
	public function handle_reload_url() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'wprsm' ) );
		}

		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		check_admin_referer( 'wprsm_reload_url_' . $id );

		global $wpdb;
		$table = $this->get_table_name();

		if ( $id && $this->table_exists() ) {
			$wpdb->update( // phpcs:ignore
				$table,
				array(
					'status'   => 'pending',
					'modified' => current_time( 'mysql' ),
				),
				array( 'id' => $id )
			);
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( add_query_arg( 'wprsm_notice', 'reloaded', $redirect ) );
		exit;
	}

	/**
	 * Remove uma URL da fila de preload (delete da linha).
	 */
	public function handle_remove_url() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'wprsm' ) );
		}

		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		check_admin_referer( 'wprsm_remove_url_' . $id );

		global $wpdb;
		$table = $this->get_table_name();

		if ( $id && $this->table_exists() ) {
			$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( add_query_arg( 'wprsm_notice', 'removed', $redirect ) );
		exit;
	}

	/**
	 * Limpa e recarrega o cache inteiro do site, usando a função nativa do WP Rocket
	 * quando disponível (mesma usada pelo botão "Limpar e gerar cache" da admin bar).
	 * Fallback: marca as linhas "completed" como "pending" pra forçar reprocessamento.
	 */
	public function handle_reload_all() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'wprsm' ) );
		}

		check_admin_referer( 'wprsm_reload_all' );

		global $wpdb;
		$table  = $this->get_table_name();
		$notice = 'reload_all_fallback';

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$notice = 'reload_all_ok';
		} elseif ( $this->table_exists() ) {
			$wpdb->query( "UPDATE {$table} SET status = 'pending' WHERE status = 'completed'" ); // phpcs:ignore
		}

		// A lista inteira vai mudar de status, então o cache de tipos continua válido,
		// mas não custa nada garantir que a próxima leitura não fique presa numa página vazia.
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		$redirect = remove_query_arg( 'wprsm_paged', $redirect );
		wp_safe_redirect( add_query_arg( 'wprsm_notice', $notice, $redirect ) );
		exit;
	}

	/**
	 * Força a execução imediata do evento de cron do preload, DENTRO da própria
	 * requisição do admin — não depende de wp-cron.php nem de requisição de loopback,
	 * então funciona mesmo com DISABLE_WP_CRON ativo e cron real do servidor configurado.
	 * Usa os mesmos argumentos com que o evento foi agendado, quando existirem.
	 */
	public function handle_run_cron_now() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'wprsm' ) );
		}

		check_admin_referer( 'wprsm_run_cron_now' );

		$hook  = self::PRELOAD_CRON_HOOK;
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( $hook ) : false;

		if ( $event && ! empty( $event->args ) ) {
			do_action_ref_array( $hook, $event->args );
		} else {
			do_action( $hook );
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( add_query_arg( 'wprsm_notice', 'cron_run_now', $redirect ) );
		exit;
	}

	/**
	 * Gera o cache de UMA URL específica agora, na hora, sem depender da ordem da fila
	 * de preload. Funciona fazendo uma requisição HTTP real pra URL — é exatamente
	 * assim que o WP Rocket gera o HTML estático (no request em si, via buffer), então
	 * isso tem o mesmo efeito de um visitante real acessar a página, mas sob demanda.
	 * Aceita 'id' (linha da fila) ou 'url' direto (ex: a home, mesmo que não esteja
	 * destacada na lista de pendentes).
	 */
	public function handle_generate_cache() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'wprsm' ) );
		}

		$id  = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$url = '';

		if ( $id ) {
			check_admin_referer( 'wprsm_generate_cache_' . $id );

			global $wpdb;
			$table = $this->get_table_name();
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
			if ( $row ) {
				$url = $row->url;
			}
		} else {
			check_admin_referer( 'wprsm_generate_cache_url' );
			$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		}

		$user_key = 'wprsm_notice_' . get_current_user_id();

		if ( ! $url ) {
			set_transient( $user_key, array( 'type' => 'error', 'message' => __( 'Nenhuma URL válida informada.', 'wprsm' ) ), 60 );
		} else {
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$url_host  = wp_parse_url( $url, PHP_URL_HOST );

			if ( ! $url_host || strtolower( $url_host ) !== strtolower( (string) $site_host ) ) {
				set_transient( $user_key, array(
					'type'    => 'error',
					/* translators: %s: host esperado. */
					'message' => sprintf( __( 'A URL precisa ser do próprio site (%s).', 'wprsm' ), $site_host ),
				), 60 );
			} else {
				// Usa o mesmo user-agent que o WP Rocket usa no preload de verdade
				// (documentado pelo próprio WP Rocket) — algumas regras de exclusão de
				// cache/hosting podem se comportar diferente dependendo do user-agent.
				$response = wp_remote_get(
					$url,
					array(
						'timeout'     => 30,
						'blocking'    => true,
						'sslverify'   => false,
						'redirection' => 3,
						'headers'     => array( 'Cache-Control' => 'no-cache' ),
						'user-agent'  => 'WP Rocket/Preload',
					)
				);

				if ( is_wp_error( $response ) ) {
					set_transient( $user_key, array(
						/* translators: 1: URL, 2: mensagem de erro. */
						'type'    => 'error',
						'message' => sprintf( __( 'Falha ao acessar %1$s: %2$s', 'wprsm' ), $url, $response->get_error_message() ),
					), 60 );
				} else {
					$code = wp_remote_retrieve_response_code( $response );

					// Prova real: existe um arquivo de cache em disco, e ele é recente?
					$cache_file    = $this->cache_file_path( $url );
					$file_exists   = file_exists( $cache_file );
					$file_is_fresh = $file_exists && ( time() - filemtime( $cache_file ) ) < 120;

					if ( $code >= 400 ) {
						$msg  = sprintf( __( 'A URL respondeu HTTP %d — não deveria ter sido cacheada.', 'wprsm' ), $code );
						$type = 'error';
					} elseif ( $file_is_fresh ) {
						$msg  = sprintf(
							/* translators: 1: URL, 2: data/hora de geração do arquivo. */
							__( 'Confirmado: arquivo de cache gerado agora para %1$s (%2$s).', 'wprsm' ),
							$url,
							wp_date( 'd/m/Y H:i:s', filemtime( $cache_file ) )
						);
						$type = 'success';
					} elseif ( $file_exists ) {
						$msg  = sprintf(
							/* translators: 1: URL, 2: data/hora do arquivo existente. */
							__( 'A requisição teve HTTP %1$d, mas o arquivo de cache em disco não é recente (última geração: %2$s) — pode não ter sido esta requisição que o gerou.', 'wprsm' ),
							$code,
							wp_date( 'd/m/Y H:i:s', filemtime( $cache_file ) )
						);
						$type = 'warning';
					} else {
						$msg = sprintf(
							/* translators: 1: URL, 2: caminho esperado do arquivo de cache. */
							__( 'A requisição teve HTTP %1$d, mas NENHUM arquivo de cache foi encontrado em %2$s. Prováveis causas: a URL está excluída do cache do WP Rocket, WP_CACHE não está ativo, ou existe um cache de borda do hosting/CDN na frente do WordPress respondendo antes dele.', 'wprsm' ),
							$code,
							str_replace( ABSPATH, '', $cache_file )
						);
						$type = 'warning';
					}

					set_transient( $user_key, array( 'type' => $type, 'message' => $msg ), 60 );
				}
			}
		}

		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : $this->current_filtered_url();
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Caminho esperado do arquivo de cache estático que o WP Rocket gera pra uma URL,
	 * seguindo a estrutura padrão dele: wp-content/cache/wp-rocket/{host}/{path}/index.html.
	 * Essa é a fonte de verdade real — bem mais confiável do que confiar só no HTTP 200
	 * da requisição ou no status gravado na tabela wpr_rocket_cache.
	 */
	private function cache_file_path( $url ) {
		$parsed = wp_parse_url( $url );
		$host   = ! empty( $parsed['host'] ) ? $parsed['host'] : wp_parse_url( home_url(), PHP_URL_HOST );
		$path   = ! empty( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';

		$dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/wp-rocket/' . $host . ( $path ? '/' . $path : '' );

		return trailingslashit( $dir ) . 'index.html';
	}

	/**
	 * Detecta hospedagens gerenciadas conhecidas por desativar automaticamente o page
	 * caching em disco do WP Rocket (documentado oficialmente pelo próprio WP Rocket:
	 * Kinsta, WP Engine, Pressable, Flywheel, SpinupWP, WordPress.com, entre outros).
	 * Detecção é só por sinais técnicos genéricos do ambiente — nada específico de projeto.
	 * Cobre com confiança os dois hosts com forma de detecção documentada publicamente;
	 * para os demais da lista, não arriscamos "adivinhar" e deixamos a nota genérica.
	 */
	private function detect_managed_host() {
		$result = array(
			'detected' => false,
			'host'     => '',
		);

		if ( function_exists( 'is_wpe' ) && is_wpe() ) {
			$result['detected'] = true;
			$result['host']     = 'WP Engine';
		} elseif ( is_dir( trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins/kinsta-mu-plugins' ) || class_exists( 'Kinsta\\Cache_Purge\\Cache_Purge' ) ) {
			$result['detected'] = true;
			$result['host']     = 'Kinsta';
		}

		return $result;
	}

	// -----------------------------------------------------------------
	// CLASSIFICAÇÃO DE TIPO (com cache para performance)
	// -----------------------------------------------------------------

	/**
	 * Tipos possíveis de conteúdo. Chave interna => label exibida.
	 * Genérico: não depende de post types ou taxonomias específicas de nenhum projeto,
	 * pois consulta o que está registrado no site em tempo de execução.
	 */
	private function type_labels() {
		return array(
			'home'     => __( 'Home', 'wprsm' ),
			'page'     => __( 'Page', 'wprsm' ),
			'single'   => __( 'Single', 'wprsm' ),
			'taxonomy' => __( 'Taxonomy', 'wprsm' ),
			'archive'  => __( 'Archive', 'wprsm' ),
			'other'    => __( 'Outro', 'wprsm' ),
		);
	}

	/**
	 * Classifica a URL usando os post types e taxonomias registrados no site
	 * (nada fixo/hardcoded), com fallback por padrões comuns de URL.
	 * Retorna a CHAVE do tipo (ver type_labels()).
	 */
	private function classify_url_raw( $url ) {
		$home_url = trailingslashit( home_url() );
		$path     = trailingslashit( $url );

		if ( $path === $home_url ) {
			return 'home';
		}

		// 1) Conteúdo singular (post, page, ou qualquer custom post type).
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			return ( 'page' === get_post_type( $post_id ) ) ? 'page' : 'single';
		}

		$parsed    = wp_parse_url( $url );
		$path_only = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
		$segments  = $path_only ? explode( '/', $path_only ) : array();

		// 2) Archive de taxonomia (category, tag, ou qualquer taxonomia customizada pública).
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$slug = ! empty( $tax->rewrite['slug'] ) ? trim( $tax->rewrite['slug'], '/' ) : $tax->name;
			if ( $slug && in_array( $slug, $segments, true ) ) {
				return 'taxonomy';
			}
		}

		// 3) Archive de post type customizado com arquivo próprio (has_archive).
		foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects' ) as $pt ) {
			$slug = is_string( $pt->has_archive )
				? trim( $pt->has_archive, '/' )
				: ( ! empty( $pt->rewrite['slug'] ) ? trim( $pt->rewrite['slug'], '/' ) : $pt->name );
			if ( $slug && in_array( $slug, $segments, true ) ) {
				return 'archive';
			}
		}

		// 4) Archive de autor ou por data (padrões nativos do WP).
		if ( in_array( 'author', $segments, true ) ) {
			return 'archive';
		}
		if ( ! empty( $segments[0] ) && preg_match( '/^\d{4}$/', $segments[0] ) ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * Classifica um lote de URLs usando um cache persistente (transient) por URL.
	 * Isso evita reprocessar url_to_postid()/regex pra URLs já vistas em carregamentos
	 * anteriores — só URLs novas ou que saíram do cache (TTL) são recalculadas.
	 * Faz apenas 1 leitura e, no máximo, 1 gravação de transient por carregamento de página,
	 * independente de quantas linhas existam.
	 *
	 * @param string[] $urls Lista de URLs a classificar.
	 * @return array URL => chave do tipo.
	 */
	private function classify_urls_cached( array $urls ) {
		$cache   = get_transient( self::TYPE_CACHE_KEY );
		$cache   = is_array( $cache ) ? $cache : array();
		$result  = array();
		$changed = false;

		foreach ( $urls as $url ) {
			if ( isset( $cache[ $url ] ) ) {
				$result[ $url ] = $cache[ $url ];
				continue;
			}

			$type           = $this->classify_url_raw( $url );
			$result[ $url ] = $type;

			if ( count( $cache ) < self::TYPE_CACHE_MAX ) {
				$cache[ $url ] = $type;
				$changed       = true;
			}
		}

		if ( $changed ) {
			set_transient( self::TYPE_CACHE_KEY, $cache, self::TYPE_CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Retorna label e cor para cada status.
	 */
	private function status_meta( $status ) {
		$map = array(
			'pending'     => array( 'label' => __( 'Pendente', 'wprsm' ), 'color' => '#8c8f94' ),
			'in-progress' => array( 'label' => __( 'Em progresso', 'wprsm' ), 'color' => '#2271b1' ),
			'completed'   => array( 'label' => __( 'Concluído', 'wprsm' ), 'color' => '#00a32a' ),
			'failed'      => array( 'label' => __( 'Falhou', 'wprsm' ), 'color' => '#d63638' ),
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : array( 'label' => ucfirst( $status ), 'color' => '#646970' );
	}

	// -----------------------------------------------------------------
	// DIAGNÓSTICO DE CAUSA
	// -----------------------------------------------------------------

	/**
	 * Checa a saúde do cron de preload: se há um próximo agendamento e se o WP-Cron
	 * "de visita" está desabilitado (o que exige cron real do servidor configurado).
	 */
	private function cron_health() {
		return array(
			'next_scheduled'   => wp_next_scheduled( self::PRELOAD_CRON_HOOK ),
			'disable_wp_cron'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
		);
	}

	/**
	 * Faz uma checagem HTTP HEAD (sem seguir redirect) numa URL específica, pra ajudar
	 * a diagnosticar por que ela falhou: 404, 403, redirect, timeout, etc.
	 */
	private function diagnose_url( $url ) {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'summary' => sprintf(
					/* translators: %s: mensagem de erro. */
					__( 'Sem resposta (%s) — provável timeout ou bloqueio de rede.', 'wprsm' ),
					$response->get_error_message()
				),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			return array(
				'ok'      => false,
				'summary' => sprintf(
					/* translators: 1: código HTTP, 2: URL de destino do redirect. */
					__( 'Redirect %1$d → %2$s', 'wprsm' ),
					$code,
					$location ? $location : __( '(sem cabeçalho Location)', 'wprsm' )
				),
			);
		}

		if ( $code >= 400 ) {
			return array(
				'ok'      => false,
				'summary' => sprintf(
					/* translators: %d: código HTTP. */
					__( 'Erro HTTP %d — URL pode estar indisponível, bloqueada ou não existir mais.', 'wprsm' ),
					$code
				),
			);
		}

		return array(
			'ok'      => true,
			'summary' => sprintf(
				/* translators: %d: código HTTP. */
				__( 'HTTP %d — a URL respondeu normalmente. Se o preload ainda marcar como falha, o problema pode ser no processamento (timeout de geração), não no acesso.', 'wprsm' ),
				$code
			),
		);
	}

	// -----------------------------------------------------------------
	// RENDER
	// -----------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sem permissão para acessar esta página.', 'wprsm' ) );
		}

		global $wpdb;
		$table = $this->get_table_name();

		echo '<div class="wrap"><h1>' . esc_html__( 'Status do Cache — WP Rocket Preload', 'wprsm' ) . '</h1>';

		if ( ! $this->table_exists() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Tabela de preload do WP Rocket não encontrada. Verifique se o plugin está ativo e se o Preload já foi iniciado ao menos uma vez.', 'wprsm' ) . '</p></div></div>';
			return;
		}

		// Avisos de ação (reload/remove/reload all).
		$notice = isset( $_GET['wprsm_notice'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice_messages = array(
			'reloaded'            => array( 'success', __( 'URL recolocada na fila (status voltou para "Pendente").', 'wprsm' ) ),
			'removed'             => array( 'success', __( 'URL removida da fila de preload.', 'wprsm' ) ),
			'reload_all_ok'       => array( 'success', __( 'Cache limpo e preload reiniciado para todo o site.', 'wprsm' ) ),
			'reload_all_fallback' => array( 'warning', __( 'Função nativa do WP Rocket não encontrada; as URLs concluídas foram marcadas como pendentes para reprocessamento no próximo cron.', 'wprsm' ) ),
			'cron_run_now'        => array( 'success', __( 'Evento de preload disparado agora, dentro desta requisição. Atualize a página em alguns segundos para ver o status mais recente.', 'wprsm' ) ),
		);
		if ( $notice && isset( $notice_messages[ $notice ] ) ) {
			list( $type, $msg ) = $notice_messages[ $notice ];
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		$dynamic_notice_key = 'wprsm_notice_' . get_current_user_id();
		$dynamic_notice      = get_transient( $dynamic_notice_key );
		if ( $dynamic_notice && is_array( $dynamic_notice ) ) {
			delete_transient( $dynamic_notice_key );
			echo '<div class="notice notice-' . esc_attr( $dynamic_notice['type'] ) . ' is-dismissible"><p>' . esc_html( $dynamic_notice['message'] ) . '</p></div>';
		}

		// --- Aviso de hosting gerenciado com page caching auto-desativado / staging ---
		$managed_host = $this->detect_managed_host();
		$is_staging   = function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type();

		if ( $managed_host['detected'] || $is_staging ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Aviso de compatibilidade de hosting:', 'wprsm' ) . '</strong></p><ul style="list-style:disc; margin-left:20px;">';

			if ( $managed_host['detected'] ) {
				echo '<li>' . sprintf(
					/* translators: %s: nome do host detectado. */
					esc_html__( 'Este site está rodando em %s. Esse tipo de hospedagem gerenciada desativa automaticamente o page caching em disco do WP Rocket (inclusive o Preload) pra evitar conflito com o cache próprio dela — isso é comportamento esperado, documentado pelo próprio WP Rocket, não um erro deste plugin.', 'wprsm' ),
					esc_html( $managed_host['host'] )
				) . '</li>';
				echo '<li>' . esc_html__( 'A pasta wp-content/cache/wp-rocket/ e a fila de preload abaixo tendem a ficar vazias ou "pendentes" para sempre nesse tipo de hospedagem — verifique o cache real pelo painel do provedor ou pelo header de resposta HTTP específico dele (ex: x-kinsta-cache no Kinsta).', 'wprsm' ) . '</li>';
			}

			if ( $is_staging ) {
				echo '<li>' . esc_html__( 'Este ambiente está marcado como "staging" (wp_get_environment_type()). Muitos provedores de hospedagem desativam completamente qualquer cache de página em ambientes de staging por padrão, pra evitar conteúdo desatualizado durante testes.', 'wprsm' ) . '</li>';
			}

			echo '<li>' . esc_html__( 'Outras hospedagens gerenciadas com esse mesmo comportamento (não detectadas automaticamente aqui): Pressable, Flywheel, SpinupWP, WordPress.com, DreamPress, Savvii, entre outras. Vale checar a documentação do seu provedor se o comportamento aqui parecer estranho.', 'wprsm' ) . '</li>';
			echo '</ul></div>';
		}

		// --- Diagnóstico de causa: saúde do cron ---
		$pending_like = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','in-progress')" ); // phpcs:ignore
		$cron         = $this->cron_health();

		if ( $pending_like > 0 && ! $cron['next_scheduled'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Possível problema de cron:', 'wprsm' ) . '</strong> ' . esc_html__( 'há URLs pendentes/em progresso, mas não encontrei o próximo agendamento do evento de preload. O processamento pode estar travado.', 'wprsm' ) . '</p></div>';
		}
		if ( $cron['disable_wp_cron'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DISABLE_WP_CRON está ativo neste site. O preload só vai avançar via cron real do servidor (ou pelo botão abaixo, que roda o evento imediatamente nesta requisição).', 'wprsm' ) . '</p>';
			if ( $cron['next_scheduled'] ) {
				echo '<p>' . sprintf(
					/* translators: %s: data/hora do próximo agendamento no fuso do site. */
					esc_html__( 'Próximo agendamento (aguardando o cron real do servidor rodar): %s', 'wprsm' ),
					esc_html( wp_date( 'd/m/Y H:i:s', $cron['next_scheduled'] ) )
				) . '</p>';
			}
			echo '</div>';
		}

		// --- Ação: forçar execução do cron do preload agora, sem depender de wp-cron.php ---
		echo '<div style="margin:0 0 16px;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="wprsm_run_cron_now">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $this->current_filtered_url() ) . '">';
		wp_nonce_field( 'wprsm_run_cron_now' );
		submit_button( __( 'Forçar execução do cron de preload agora', 'wprsm' ), 'secondary', 'submit', false );
		echo '</form>';
		echo ' <span class="description">' . esc_html__( 'Roda o evento imediatamente nesta requisição, sem depender do wp-cron.php ou do cron do servidor.', 'wprsm' ) . '</span>';
		echo '</div>';

		// Filtros via GET (somente leitura, sem alterar dados).
		$status_filter = isset( $_GET['wprsm_status'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter   = isset( $_GET['wprsm_type'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged         = isset( $_GET['wprsm_paged'] ) ? max( 1, intval( wp_unslash( $_GET['wprsm_paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby       = isset( $_GET['wprsm_orderby'] ) ? sanitize_key( wp_unslash( $_GET['wprsm_orderby'] ) ) : 'last_accessed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order         = ( isset( $_GET['wprsm_order'] ) && 'asc' === $_GET['wprsm_order'] ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$base_url = esc_url( menu_page_url( self::PAGE_SLUG, false ) );

		// --- Ação: gerar cache de uma URL específica agora, com prioridade, sem esperar a fila ---
		echo '<div style="margin:0 0 16px; padding:12px 16px; background:#fff; border:1px solid #dcdcde;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Gerar cache de uma URL específica agora', 'wprsm' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Faz uma requisição real na URL agora, gerando o cache dela na hora — sem esperar a vez na fila de preload. Útil para priorizar a home ou qualquer página específica durante testes.', 'wprsm' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
		echo '<input type="hidden" name="action" value="wprsm_generate_cache">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $this->current_filtered_url() ) . '">';
		wp_nonce_field( 'wprsm_generate_cache_url' );
		echo '<input type="url" name="url" placeholder="' . esc_attr( home_url( '/' ) ) . '" value="' . esc_attr( home_url( '/' ) ) . '" style="min-width:360px;" required>';
		submit_button( __( 'Gerar cache agora', 'wprsm' ), 'primary', 'submit', false );
		echo '</form>';
		echo '</div>';

		// --- Ação: recarregar tudo ---
		echo '<div style="margin:16px 0;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Limpar e recarregar o cache de todo o site?', 'wprsm' ) ) . '\');">';
		echo '<input type="hidden" name="action" value="wprsm_reload_all">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $this->current_filtered_url() ) . '">';
		wp_nonce_field( 'wprsm_reload_all' );
		submit_button( __( 'Limpar e recarregar cache do site inteiro', 'wprsm' ), 'primary', 'submit', false );
		echo '</form>';
		echo '</div>';

		// --- Resumo por status (contagem real via SQL, rápida, não depende do FETCH_CAP) ---
		$status_counts = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore

		echo '<h2>' . esc_html__( 'Resumo por status', 'wprsm' ) . '</h2>';
		echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px;">';

		$clear_status_link = esc_url( remove_query_arg( array( 'wprsm_status', 'wprsm_paged' ), add_query_arg( array(), $base_url ) ) );
		echo '<a href="' . $clear_status_link . '" class="button">' . esc_html__( 'Ver todos os status', 'wprsm' ) . '</a>';

		if ( $status_counts ) {
			foreach ( $status_counts as $row ) {
				$meta = $this->status_meta( $row['status'] );
				$link = esc_url( add_query_arg( array( 'wprsm_status' => $row['status'], 'wprsm_paged' => false ), $base_url ) );
				echo '<a href="' . $link . '" style="text-decoration:none;">';
				echo '<div style="border-left:4px solid ' . esc_attr( $meta['color'] ) . '; background:#fff; padding:10px 16px; min-width:140px; box-shadow:0 1px 1px rgba(0,0,0,.04);">';
				echo '<strong style="font-size:20px;">' . intval( $row['total'] ) . '</strong><br>';
				echo '<span style="color:' . esc_attr( $meta['color'] ) . '; font-weight:600;">' . esc_html( $meta['label'] ) . '</span>';
				echo '</div></a>';
			}
		} else {
			echo '<p>' . esc_html__( 'Nenhum registro de preload encontrado ainda.', 'wprsm' ) . '</p>';
		}
		echo '</div>';

		// Legenda de status.
		echo '<h3>' . esc_html__( 'Legenda de status', 'wprsm' ) . '</h3>';
		echo '<ul style="list-style:none; padding:0; display:flex; gap:20px; flex-wrap:wrap; margin-bottom:24px;">';
		foreach ( array( 'pending', 'in-progress', 'completed', 'failed' ) as $status_key ) {
			$meta = $this->status_meta( $status_key );
			echo '<li><span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:' . esc_attr( $meta['color'] ) . '; margin-right:6px;"></span>' . esc_html( $meta['label'] ) . '</li>';
		}
		echo '<li><span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#fff; border:2px solid #dba617; margin-right:6px;"></span>' . esc_html__( 'Parada há mais de 15 min (possível travamento)', 'wprsm' ) . '</li>';
		echo '</ul>';

		// --- Diagnóstico pontual de uma URL (se solicitado) ---
		if ( isset( $_GET['wprsm_check_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$check_id = intval( $_GET['wprsm_check_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $check_id && check_admin_referer( 'wprsm_check_' . $check_id, 'wprsm_check_nonce' ) ) {
				$check_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $check_id ) ); // phpcs:ignore
				if ( $check_row ) {
					$diag = $this->diagnose_url( $check_row->url );
					$box_class = $diag['ok'] ? 'notice-info' : 'notice-error';
					echo '<div class="notice ' . esc_attr( $box_class ) . '"><p><strong>' . esc_html__( 'Diagnóstico', 'wprsm' ) . ':</strong> ' . esc_html( $check_row->url ) . '<br>' . esc_html( $diag['summary'] ) . '</p></div>';
				}
			}
		}

		// --- Busca as linhas (respeitando filtro de status), até o teto FETCH_CAP ---
		if ( $status_filter ) {
			$fetched = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY last_accessed DESC LIMIT %d", $status_filter, self::FETCH_CAP ) ); // phpcs:ignore
		} else {
			$fetched = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_accessed DESC LIMIT %d", self::FETCH_CAP ) ); // phpcs:ignore
		}

		$fetched_count = is_array( $fetched ) ? count( $fetched ) : 0;

		// Classifica todas as URLs de uma vez, usando o cache persistente (performance).
		$urls_to_classify = wp_list_pluck( (array) $fetched, 'url' );
		$type_map         = $this->classify_urls_cached( $urls_to_classify );

		$type_labels = $this->type_labels();
		$type_counts = array_fill_keys( array_keys( $type_labels ), 0 );
		$classified  = array();
		$now_ts      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		foreach ( (array) $fetched as $row ) {
			$type = isset( $type_map[ $row->url ] ) ? $type_map[ $row->url ] : 'other';
			$type_counts[ $type ]++;

			$ref_time  = ! empty( $row->last_accessed ) ? strtotime( $row->last_accessed ) : false;
			$is_stuck  = in_array( $row->status, array( 'pending', 'in-progress' ), true )
				&& $ref_time
				&& ( $now_ts - $ref_time ) > self::STUCK_THRESHOLD;

			$classified[] = array(
				'row'      => $row,
				'type'     => $type,
				'is_stuck' => $is_stuck,
			);
		}

		// Aplica filtro de tipo, se houver.
		if ( $type_filter && isset( $type_labels[ $type_filter ] ) ) {
			$classified = array_values( array_filter( $classified, function( $item ) use ( $type_filter ) {
				return $item['type'] === $type_filter;
			} ) );
		}

		// --- Ordenação ---
		$sortable = array( 'url', 'type', 'status', 'last_accessed' );
		if ( in_array( $orderby, $sortable, true ) ) {
			usort( $classified, function( $a, $b ) use ( $orderby, $order ) {
				if ( 'type' === $orderby ) {
					$va = $a['type'];
					$vb = $b['type'];
				} else {
					$va = $a['row']->{$orderby};
					$vb = $b['row']->{$orderby};
				}
				$cmp = strcmp( (string) $va, (string) $vb );
				return ( 'asc' === $order ) ? $cmp : -$cmp;
			} );
		}

		// --- Filtro por tipo (chips) ---
		echo '<h2>' . esc_html__( 'Filtrar por tipo de conteúdo', 'wprsm' ) . '</h2>';
		echo '<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">';

		$clear_type_link = esc_url( remove_query_arg( array( 'wprsm_type', 'wprsm_paged' ), add_query_arg( array(), $base_url . ( $status_filter ? '&wprsm_status=' . rawurlencode( $status_filter ) : '' ) ) ) );
		$all_types_class = $type_filter ? 'button' : 'button button-primary';
		echo '<a href="' . $clear_type_link . '" class="' . esc_attr( $all_types_class ) . '">' . esc_html__( 'Todos os tipos', 'wprsm' ) . '</a>';

		foreach ( $type_labels as $key => $label ) {
			$link_args = array( 'wprsm_type' => $key, 'wprsm_paged' => false );
			if ( $status_filter ) {
				$link_args['wprsm_status'] = $status_filter;
			}
			$link       = esc_url( add_query_arg( $link_args, $base_url ) );
			$is_current = ( $type_filter === $key );
			$btn_class  = $is_current ? 'button button-primary' : 'button';
			echo '<a href="' . $link . '" class="' . esc_attr( $btn_class ) . '">' . esc_html( $label ) . ' (' . intval( $type_counts[ $key ] ) . ')</a>';
		}
		echo '</div>';

		if ( $fetched_count >= self::FETCH_CAP ) {
			echo '<div class="notice notice-warning"><p>' . sprintf(
				/* translators: %d: número máximo de linhas processadas. */
				esc_html__( 'Mostrando os %d registros mais recentes para este filtro de status. Refine por status ou tipo para ver um recorte diferente.', 'wprsm' ),
				intval( self::FETCH_CAP )
			) . '</p></div>';
		}

		// --- Paginação ---
		$total_filtered = count( $classified );
		$total_pages    = max( 1, (int) ceil( $total_filtered / self::PER_PAGE ) );
		$paged          = min( $paged, $total_pages );
		$offset         = ( $paged - 1 ) * self::PER_PAGE;
		$page_items     = array_slice( $classified, $offset, self::PER_PAGE );

		echo '<h2>' . esc_html__( 'URLs', 'wprsm' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: 1: total de itens no filtro atual, 2: página atual, 3: total de páginas. */
			esc_html__( '%1$d URL(s) neste filtro — página %2$d de %3$d.', 'wprsm' ),
			intval( $total_filtered ),
			intval( $paged ),
			intval( $total_pages )
		) . '</p>';

		if ( ! $page_items ) {
			echo '<p>' . esc_html__( 'Nenhuma URL encontrada para este filtro.', 'wprsm' ) . '</p></div>';
			return;
		}

		// Helper pra montar link de ordenação de uma coluna.
		$sort_link = function( $column, $label ) use ( $base_url, $status_filter, $type_filter, $orderby, $order ) {
			$next_order = ( $orderby === $column && 'asc' === $order ) ? 'desc' : 'asc';
			$args       = array( 'wprsm_orderby' => $column, 'wprsm_order' => $next_order );
			if ( $status_filter ) {
				$args['wprsm_status'] = $status_filter;
			}
			if ( $type_filter ) {
				$args['wprsm_type'] = $type_filter;
			}
			$arrow = '';
			if ( $orderby === $column ) {
				$arrow = ( 'asc' === $order ) ? ' &uarr;' : ' &darr;';
			}
			return '<a href="' . esc_url( add_query_arg( $args, $base_url ) ) . '">' . esc_html( $label ) . $arrow . '</a>';
		};

		$redirect_to = esc_attr( $this->current_filtered_url() );

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . $sort_link( 'url', __( 'URL', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . $sort_link( 'type', __( 'Tipo', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . $sort_link( 'status', __( 'Status', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . $sort_link( 'last_accessed', __( 'Última atualização', 'wprsm' ) ) . '</th>'; // phpcs:ignore
		echo '<th>' . esc_html__( 'Ações', 'wprsm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $page_items as $item ) {
			$row        = $item['row'];
			$meta       = $this->status_meta( $row->status );
			$type_label = isset( $type_labels[ $item['type'] ] ) ? $type_labels[ $item['type'] ] : $item['type'];
			$row_style  = $item['is_stuck'] ? ' style="background:#fff8e5;"' : '';

			echo '<tr' . $row_style . '>'; // phpcs:ignore
			echo '<td><a href="' . esc_url( $row->url ) . '" target="_blank" rel="noopener">' . esc_html( $row->url ) . '</a></td>';
			echo '<td>' . esc_html( $type_label ) . '</td>';
			echo '<td><span style="color:' . esc_attr( $meta['color'] ) . '; font-weight:600;">&#9679; ' . esc_html( $meta['label'] );
			if ( $item['is_stuck'] ) {
				echo ' <span style="color:#dba617;" title="' . esc_attr__( 'Parada há mais de 15 minutos', 'wprsm' ) . '">&#9888;</span>';
			}
			echo '</span></td>';
			echo '<td>' . esc_html( isset( $row->last_accessed ) ? $row->last_accessed : '—' ) . '</td>';

			echo '<td style="white-space:nowrap;">';

			// Diagnosticar causa (só faz sentido para failed, mas deixo disponível sempre).
			$check_url = wp_nonce_url(
				add_query_arg( 'wprsm_check_id', $row->id, $this->current_filtered_url() ),
				'wprsm_check_' . $row->id,
				'wprsm_check_nonce'
			);
			echo '<a href="' . esc_url( $check_url ) . '" class="button button-small">' . esc_html__( 'Diagnosticar', 'wprsm' ) . '</a> ';

			// Gerar cache agora (prioridade, sem esperar a fila).
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="wprsm_generate_cache">';
			echo '<input type="hidden" name="id" value="' . intval( $row->id ) . '">';
			echo '<input type="hidden" name="redirect_to" value="' . $redirect_to . '">'; // phpcs:ignore
			wp_nonce_field( 'wprsm_generate_cache_' . $row->id );
			echo '<button type="submit" class="button button-small button-primary">' . esc_html__( 'Gerar cache agora', 'wprsm' ) . '</button>';
			echo '</form> ';

			// Recarregar URL individual.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="wprsm_reload_url">';
			echo '<input type="hidden" name="id" value="' . intval( $row->id ) . '">';
			echo '<input type="hidden" name="redirect_to" value="' . $redirect_to . '">'; // phpcs:ignore
			wp_nonce_field( 'wprsm_reload_url_' . $row->id );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Recarregar', 'wprsm' ) . '</button>';
			echo '</form> ';

			// Remover da fila.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Remover esta URL da fila de preload?', 'wprsm' ) ) . '\');">';
			echo '<input type="hidden" name="action" value="wprsm_remove_url">';
			echo '<input type="hidden" name="id" value="' . intval( $row->id ) . '">';
			echo '<input type="hidden" name="redirect_to" value="' . $redirect_to . '">'; // phpcs:ignore
			wp_nonce_field( 'wprsm_remove_url_' . $row->id );
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Remover', 'wprsm' ) . '</button>';
			echo '</form>';

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Links de paginação.
		if ( $total_pages > 1 ) {
			echo '<div style="margin-top:16px; display:flex; gap:8px; align-items:center;">';

			$pag_base_args = array();
			if ( $status_filter ) {
				$pag_base_args['wprsm_status'] = $status_filter;
			}
			if ( $type_filter ) {
				$pag_base_args['wprsm_type'] = $type_filter;
			}
			if ( 'last_accessed' !== $orderby ) {
				$pag_base_args['wprsm_orderby'] = $orderby;
				$pag_base_args['wprsm_order']   = $order;
			}

			if ( $paged > 1 ) {
				$prev_link = esc_url( add_query_arg( array_merge( $pag_base_args, array( 'wprsm_paged' => $paged - 1 ) ), $base_url ) );
				echo '<a href="' . $prev_link . '" class="button">&laquo; ' . esc_html__( 'Anterior', 'wprsm' ) . '</a>';
			}

			echo '<span>' . sprintf(
				/* translators: 1: página atual, 2: total de páginas. */
				esc_html__( 'Página %1$d de %2$d', 'wprsm' ),
				intval( $paged ),
				intval( $total_pages )
			) . '</span>';

			if ( $paged < $total_pages ) {
				$next_link = esc_url( add_query_arg( array_merge( $pag_base_args, array( 'wprsm_paged' => $paged + 1 ) ), $base_url ) );
				echo '<a href="' . $next_link . '" class="button">' . esc_html__( 'Próxima', 'wprsm' ) . ' &raquo;</a>';
			}

			echo '</div>';
		}

		echo '</div>';
	}
}

new WPRSM_Status_Monitor();
