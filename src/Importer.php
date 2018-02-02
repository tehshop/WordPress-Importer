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
}
