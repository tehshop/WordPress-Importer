<?php
/**
 * The main importer class, extending the slightly modified WP importer 2.0 class WXRImporter
 */

namespace ProteusThemes\WPContentImporter2;

use WP_Error;
use XMLReader;

class Importer extends WXRImporter {

	/**
	 * Importer constructor.
	 * Look at the parent constructor for the options parameters.
	 *
	 * @param array  $options The importer options.
	 * @param object $logger  The logger object.
	 */
	public function __construct( $options = array(), $logger = null ) {
		parent::__construct( $options );

		$this->set_logger( $logger );
	}

	/**
	 * Get the XML reader for the file.
	 *
	 * @param string $file Path to the XML file.
	 *
	 * @return XMLReader|boolean Reader instance on success, false otherwise.
	 */
	protected function get_reader( $file ) {
		// Avoid loading external entities for security
		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) ) {
			// $old_value = libxml_disable_entity_loader( true );
		}

		if ( ! class_exists( 'XMLReader' ) ) {
			$this->logger->critical( __( 'The XMLReader class is missing! Please install the XMLReader PHP extension on your server', 'wordpress-importer' ) );

			return false;
		}

		$reader = new XMLReader();
		$status = $reader->open( $file );

		if ( ! is_null( $old_value ) ) {
			// libxml_disable_entity_loader( $old_value );
		}

		if ( ! $status ) {
			$this->logger->error( __( 'Could not open the XML file for parsing!', 'wordpress-importer' ) );

			return false;
		}

		return $reader;
	}

	/**
	 * Get the basic import content data.
	 * Which elements are present in this import file (check possible elements in the $data variable)?
	 *
	 * @param $file
	 *
	 * @return array|bool
	 */
	public function get_basic_import_content_data( $file ) {
		$data = array(
			'users'      => false,
			'categories' => false,
			'tags'       => false,
			'terms'      => false,
			'posts'      => false,
		);

		// Get the XML reader and open the file.
		$reader = $this->get_reader( $file );

		if ( empty( $reader ) ) {
			return false;
		}

		// Start parsing!
		while ( $reader->read() ) {
			// Only deal with element opens.
			if ( $reader->nodeType !== XMLReader::ELEMENT ) {
				continue;
			}

			switch ( $reader->name ) {
				case 'wp:author':
					// Skip, if the users were already detected.
					if ( $data['users'] ) {
						$reader->next();
						break;
					}

					$node   = $reader->expand();
					$parsed = $this->parse_author_node( $node );

					// Skip, if there was an error in parsing the author node.
					if ( is_wp_error( $parsed ) ) {
						$reader->next();
						break;
					}

					$data['users'] = true;

					// Handled everything in this node, move on to the next.
					$reader->next();
					break;

				case 'item':
					// Skip, if the posts were already detected.
					if ( $data['posts'] ) {
						$reader->next();
						break;
					}

					$node   = $reader->expand();
					$parsed = $this->parse_post_node( $node );

					// Skip, if there was an error in parsing the item node.
					if ( is_wp_error( $parsed ) ) {
						$reader->next();
						break;
					}

					$data['posts'] = true;

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:category':
					$data['categories'] = true;

					// Handled everything in this node, move on to the next
					$reader->next();
					break;
				case 'wp:tag':
					$data['tags'] = true;

					// Handled everything in this node, move on to the next
					$reader->next();
					break;
				case 'wp:term':
					$data['terms'] = true;

					// Handled everything in this node, move on to the next
					$reader->next();
					break;
			}
		}

		return $data;
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file    Path to the WXR file for importing.
	 * @param array  $options Import options (which parts to import).
	 *
	 * @return boolean
	 */
	public function import( $file, $options = array() ) {
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		// Set the import options defaults.
		if ( empty( $options ) ) {
			$options = array(
				'users'      => false,
				'categories' => true,
				'tags'       => true,
				'terms'      => true,
				'posts'      => true,
			);
		}

		$result = $this->import_start( $file );

		if ( is_wp_error( $result ) ) {
			$this->logger->error( __( 'Content import start error: ', 'wordpress-importer' ) . $result->get_error_message() );

			return false;
		}

		// Get the actual XML reader.
		$reader = $this->get_reader( $file );

		if ( empty( $reader ) ) {
			return false;
		}

		// Set the version to compatibility mode first
		$this->version = '1.0';

		// Reset other variables
		$this->base_url = '';

		// Start parsing!
		while ( $reader->read() ) {
			// Only deal with element opens.
			if ( $reader->nodeType !== XMLReader::ELEMENT ) {
				continue;
			}

			switch ( $reader->name ) {
				case 'wp:wxr_version':
					// Upgrade to the correct version
					$this->version = $reader->readString();

					if ( version_compare( $this->version, self::MAX_WXR_VERSION, '>' ) ) {
						$this->logger->warning( sprintf(
							__( 'This WXR file (version %s) is newer than the importer (version %s) and may not be supported. Please consider updating.', 'wordpress-importer' ),
							$this->version,
							self::MAX_WXR_VERSION
						) );
					}

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:base_site_url':
					$this->base_url = $reader->readString();

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'item':
					if ( empty( $options['posts'] ) ) {
						$reader->next();
						break;
					}

					$node   = $reader->expand();
					$parsed = $this->parse_post_node( $node );

					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$this->process_post( $parsed['data'], $parsed['meta'], $parsed['comments'], $parsed['terms'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:author':
					if ( empty( $options['users'] ) ) {
						$reader->next();
						break;
					}

					$node   = $reader->expand();
					$parsed = $this->parse_author_node( $node );

					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_author( $parsed['data'], $parsed['meta'] );

					if ( is_wp_error( $status ) ) {
						$this->log_error( $status );
					}

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:category':
					if ( empty( $options['categories'] ) ) {
						$reader->next();
						break;
					}

					$node   = $reader->expand();
					$parsed = $this->parse_term_node( $node, 'category' );

					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_term( $parsed['data'], $parsed['meta'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:tag':
					if ( empty( $options['tags'] ) ) {
						$reader->next();
						break;
					}

					$node   = $reader->expand();
					$parsed = $this->parse_term_node( $node, 'tag' );

					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_term( $parsed['data'], $parsed['meta'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				case 'wp:term':
					if ( empty( $options['terms'] ) ) {
						$reader->next();
						break;
					}

					$node   = $reader->expand();
					$parsed = $this->parse_term_node( $node );

					if ( is_wp_error( $parsed ) ) {
						$this->log_error( $parsed );

						// Skip the rest of this post
						$reader->next();
						break;
					}

					$status = $this->process_term( $parsed['data'], $parsed['meta'] );

					// Handled everything in this node, move on to the next
					$reader->next();
					break;

				default:
					// Skip this node, probably handled by something already
					break;
			}
		}

		// Now that we've done the main processing, do any required
		// post-processing and remapping.
		$this->post_process();

		if ( $this->options['aggressive_url_search'] ) {
			$this->replace_attachment_urls_in_content();
		}

		$this->remap_featured_images();

		$this->import_end();

		return true;
	}
}
