<?php

/**
 * @file
 * Contains \Drupal\search_api_solr\Solr\SolrHelper.
 */

namespace Drupal\search_api_solr\Solr;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Utility as SearchApiUtility;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\Utility\Utility as SearchApiSolrUtility;
use Solarium\Client;
use Solarium\Core\Client\Request;
use Solarium\Core\Query\Helper as SolariumHelper;
use Solarium\Exception\HttpException;
use Solarium\Exception\OutOfBoundsException;
use Solarium\QueryType\Select\Query\Query;

/**
 * Contains helper methods for working with Solr.
 */
class SolrHelper {

  /**
   * A connection to the Solr server.
   *
   * @var \Solarium\Client
   */
  protected $solr;

  /**
   * A connection to the Solr server.
   *
   * @var array
   */
  protected $configuration;

  /**
   * Stores Solr server information.
   *
   * @var array
   */
  protected $serverInfo;

  /**
   * Stores Solr core system information.
   *
   * @var array
   */
  protected $systemInfo;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration) {
    $this->configuration = $configuration;
  }

  /**
   * Sets the solr connection.
   *
   * @param \Solarium\Client $solr
   *   The solarium connection object.
   */
  public function setSolr(Client $solr) {
    $this->solr = $solr;
    try {
      $this->solr->getEndpoint('server');
    }
    catch (OutOfBoundsException $e) {
      $this->attachServerEndpoint();
    }
  }

  /**
   * Attaches an endpoint to the Solr connection to communicate with the server.
   *
   * This endpoint is different from the core endpoint which is the default one.
   * The default endpoint for the core is used to communicate with the index.
   * But for some administrative tasks the server itself needs to be contacted.
   * This function is meant to be overwritten as soon as we deal with Solr
   * service provider specific implementations of SolrHelper.
   */
  public function attachServerEndpoint() {
    $configuration = $this->configuration;
    $configuration['core'] = NULL;
    $configuration['key'] = 'server';
    $this->solr->createEndpoint($configuration);
  }

  /**
   * Returns a the Solr server URI.
   */
  protected function getServerUri() {
    $url_path = $this->solr->getEndpoint('server')->getBaseUri();
    if ($this->configuration['host'] == 'localhost' && !empty($_SERVER['SERVER_NAME'])) {
      $url_path = str_replace('localhost', $_SERVER['SERVER_NAME'], $url_path);
    }

    return $url_path;
  }

  /**
   * Returns a link to the Solr server.
   */
  public function getServerLink() {
    $url_path = $this->getServerUri();
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * Returns a link to the Solr core, if the necessary options are set.
   */
  public function getCoreLink() {
    $url_path = $this->getServerUri() . '#/' . $this->configuration['core'];
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * Extract and format highlighting information for a specific item from a Solr response.
   *
   * Will also use highlighted fields to replace retrieved field data, if the
   * corresponding option is set.
   */
  public function getExcerpt($response, $solr_id, array $fields, array $field_mapping) {
    if (!isset($response['highlighting'][$solr_id])) {
      return FALSE;
    }
    $output = '';

    if (!empty($this->configuration['excerpt']) && !empty($response['highlighting'][$solr_id]->spell)) {
      foreach ($response['highlighting'][$solr_id]->spell as $snippet) {
        $snippet = strip_tags($snippet);
        $snippet = preg_replace('/^.*>|<.*$/', '', $snippet);
        $snippet = SearchApiSolrUtility::formatHighlighting($snippet);
        // The created fragments sometimes have leading or trailing punctuation.
        // We remove that here for all common cases, but take care not to remove
        // < or > (so HTML tags stay valid).
        $snippet = trim($snippet, "\00..\x2F:;=\x3F..\x40\x5B..\x60");
        $output .= $snippet . ' … ';
      }
    }
    if (!empty($this->configuration['highlight_data'])) {
      foreach ($field_mapping as $search_api_property => $solr_property) {
        if (substr($solr_property, 0, 3) == 'tm_' && !empty($response['highlighting'][$solr_id][$solr_property])) {
          // Contrary to above, we here want to preserve HTML, so we just
          // replace the [HIGHLIGHT] tags with the appropriate format.
          $snippet = SearchApiSolrUtility::formatHighlighting($response['highlighting'][$solr_id][$solr_property]);
          $fields[$search_api_property] = $snippet;
        }
      }
    }

    return $output;
  }

  /**
   * Flatten a keys array into a single search string.
   *
   * @param array $keys
   *   The keys array to flatten, formatted as specified by
   *   \Drupal\search_api\Query\QueryInterface::getKeys().
   * @param bool $is_nested
   *   (optional) Whether the function is called for a nested condition.
   *   Defaults to FALSE.
   *
   * @return string
   *   A Solr query string representing the same keys.
   */
  public function flattenKeys(array $keys, $is_nested = FALSE) {
    $k = array();
    $or = $keys['#conjunction'] == 'OR';
    $neg = !empty($keys['#negation']);
    foreach ($keys as $key_nr => $key) {
      // We cannot use \Drupal\Core\Render\Element::children() anymore because
      // $keys is not a valid render array.
      if ($key_nr[0] === '#' || !$key) {
        continue;
      }
      if (is_array($key)) {
        $subkeys = $this->flattenKeys($key, TRUE);
        if ($subkeys) {
          $nested_expressions = TRUE;
          // If this is a negated OR expression, we can't just use nested keys
          // as-is, but have to put them into parantheses.
          if ($or && $neg) {
            $subkeys = "($subkeys)";
          }
          $k[] = $subkeys;
        }
      }
      else {
        $solariumHelper = new SolariumHelper();
        $key = $solariumHelper->escapePhrase(trim($key));
        $k[] = $key;
      }
    }
    if (!$k) {
      return '';
    }

    // Formatting the keys into a Solr query can be a bit complex. The following
    // code will produce filters that look like this:
    //
    // #conjunction | #negation | return value
    // ----------------------------------------------------------------
    // AND          | FALSE     | A B C
    // AND          | TRUE      | -(A AND B AND C)
    // OR           | FALSE     | ((A) OR (B) OR (C))
    // OR           | TRUE      | -A -B -C

    // If there was just a single, unnested key, we can ignore all this.
    if (count($k) == 1 && empty($nested_expressions)) {
      $k = reset($k);
      return $neg ? "*:* AND -$k" : $k;
    }

    if ($or) {
      if ($neg) {
        return '*:* AND -' . implode(' AND -', $k);
      }
      return '((' . implode(') OR (', $k) . '))';
    }
    $k = implode($neg || $is_nested ? ' AND ' : ' ', $k);
    return $neg ? "*:* AND -($k)" : $k;
  }

  /**
   * Gets the current Solr version.
   *
   * @param bool $force_auto_detect
   *   If TRUE, ignore user overwrites.
   *
   * @return string
   *   The full Solr version string.
   */
  public function getSolrVersion($force_auto_detect = FALSE) {
    // Allow for overrides by the user.
    if (!$force_auto_detect && !empty($this->configuration['solr_version'])) {
      // In most cases the already stored solr_version is just the major version
      // number as integer. In this case we will expand it to the minimum
      // corresponding full version string.
      $version = explode('.', $this->configuration['solr_version']) + ['0' ,'0', '0'];
      return implode('.', $version);
    }

    $server_info = $this->getServerInfo();
    // Get our solr version number
    if (isset($server_info['lucene']['solr-spec-version'])) {
      return $server_info['lucene']['solr-spec-version'];
    }

    return '0.0.0';
  }

  /**
   * Gets the current Solr major version.
   *
   * @param string $version
   *   An optional Solr version string.
   *
   * @return integer
   *   The Solr major version.
   */
  public function getSolrMajorVersion($version = '') {
    list($major, , ) = explode('.', $version ?: $this->getSolrVersion());
    return $major;
  }

  /**
   * Gets the current Solr branch name.
   *
   * @param string $version
   *   An optional Solr version string.
   *
   * @return string
   *   The Solr branch string.
   */
  public function getSolrBranch($version = '') {
    return $this->getSolrMajorVersion($version) . '.x';
  }

  /**
   * Gets information about the Solr server.
   *
   * @return object
   *   A response object with server information.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getServerInfo() {
    // @todo Add back persistent cache?
    if (!isset($this->serverInfo)) {
      // @todo Finish https://github.com/solariumphp/solarium/pull/155 and stop
      // abusing the ping query for this.
      $query = $this->solr->createPing(array('handler' => 'admin/info/system'));
      try {
        $this->serverInfo = $this->solr->execute($query, 'server')->getData();
      }
      catch (HttpException $e) {
        throw new SearchApiSolrException(t('Solr server @server not found.', ['@server' => $this->solr->getEndpoint('server')->getBaseUri()]), $e->getCode(), $e);
      }
    }

    return $this->serverInfo;
  }

  /**
   * Gets information about the Solr Core.
   *
   * @return object
   *   A response object with system information.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getSystemInfo() {
    // @todo Add back persistent cache?
    if (!isset($this->systemInfo)) {
      // @todo Finish https://github.com/solariumphp/solarium/pull/155 and stop
      // abusing the ping query for this.
      $query = $this->solr->createPing(array('handler' => 'admin/system'));
      try {
        $this->systemInfo = $this->solr->execute($query)->getData();
      }
      catch (HttpException $e) {
        throw new SearchApiSolrException(t('Solr server core @core not found.', ['@core' => $this->solr->getEndpoint()->getBaseUri()]), $e->getCode(), $e);
      }
    }

    return $this->systemInfo;
  }

  /**
   * Gets meta-data about the index.
   *
   * @return object
   *   A response object filled with data from Solr's Luke.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getLuke() {
    // @todo Write a patch for Solarium to have a separate Luke query and stop
    // abusing the ping query for this.
    $query = $this->solr->createPing(array('handler' => 'admin/luke'));
    try {
      return $this->solr->execute($query)->getData();
    }
    catch (HttpException $e) {
      throw new SearchApiSolrException(t('Solr server core @core not found.', ['@core' => $this->solr->getEndpoint()->getBaseUri()]), $e->getCode(), $e);
    }
  }

  /**
   * Gets summary information about the Solr Core.
   *
   * @return array
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getStatsSummary() {
    $summary = array(
      '@pending_docs' => '',
      '@autocommit_time_seconds' => '',
      '@autocommit_time' => '',
      '@deletes_by_id' => '',
      '@deletes_by_query' => '',
      '@deletes_total' => '',
      '@schema_version' => '',
      '@core_name' => '',
      '@index_size' => '',
    );

    $solr_version = $this->getSolrVersion();
    $query = $this->solr->createPing();
    $query->setResponseWriter(Query::WT_PHPS);
    $query->setHandler('admin/mbeans?stats=true');
    try {
      $stats = $this->solr->execute($query)->getData();
      if (!empty($stats)) {
        $update_handler_stats = $stats['solr-mbeans']['UPDATEHANDLER']['updateHandler']['stats'];
        $summary['@pending_docs'] = (int) $update_handler_stats['docsPending'];
        $max_time = (int) $update_handler_stats['autocommit maxTime'];
        // Convert to seconds.
        $summary['@autocommit_time_seconds'] = $max_time / 1000;
        $summary['@autocommit_time'] = \Drupal::service('date.formatter')->formatInterval($max_time / 1000);
        $summary['@deletes_by_id'] = (int) $update_handler_stats['deletesById'];
        $summary['@deletes_by_query'] = (int) $update_handler_stats['deletesByQuery'];
        $summary['@deletes_total'] = $summary['@deletes_by_id'] + $summary['@deletes_by_query'];
        $summary['@schema_version'] = $this->getSystemInfo()['core']['schema'];
        $summary['@core_name'] = $stats['solr-mbeans']['CORE']['core']['stats']['coreName'];
        $summary['@index_size'] = $stats['solr-mbeans']['QUERYHANDLER']['/replication']['stats']['indexSize'];
      }
      return $summary;
    }
    catch (HttpException $e) {
      throw new SearchApiSolrException(t('Solr server core @core not found.', ['@core' => $this->solr->getEndpoint()->getBaseUri()]), $e->getCode(), $e);
    }
  }

  /**
   * Sets the highlighting parameters.
   *
   * (The $query parameter currently isn't used and only here for the potential
   * sake of subclasses.)
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query object.
   * @param \Solarium\QueryType\Select\Query\Query $solarium_query
   *   The Solarium select query object.
   */
  public function setHighlighting(Query $solarium_query, QueryInterface $query, $highlight = true, $excerpt = true) {
    if ($excerpt || $highlight) {
      $hl = $solarium_query->getHighlighting();
      $hl->setFields('spell');
      $hl->setSimplePrefix('[HIGHLIGHT]');
      $hl->setSimplePostfix('[/HIGHLIGHT]');
      $hl->setSnippets(3);
      $hl->setFragSize(70);
      $hl->setMergeContiguous(TRUE);
    }

    if ($highlight) {
      $hl = $solarium_query->getHighlighting();
      $hl->setFields('tm_*');
      $hl->setSnippets(1);
      $hl->setFragSize(0);
      if (!empty($this->configuration['excerpt'])) {
        // If we also generate a "normal" excerpt, set the settings for the
        // "spell" field (which we use to generate the excerpt) back to the
        // above values.
        $hl->getField('spell')->setSnippets(3);
        $hl->getField('spell')->setFragSize(70);
        // It regrettably doesn't seem to be possible to set hl.fl to several
        // values, if one contains wild cards (i.e., "t_*,spell" wouldn't work).
        $hl->setFields('*');
      }
    }
  }

  /**
   * Changes the query to a "More Like This"  query.
   */
  public function setMoreLikeThis(Query &$solarium_query, QueryInterface $query, $mlt_options = array(), $index_fields = array(), $fields) {
    $solarium_query = $this->solr->createMoreLikeThis(array('handler' => 'select'));
    // The fields to look for similarities in.
    if (empty($mlt_options['fields'])) {
      return;
    }

    $mlt_fl = array();
    foreach ($mlt_options['fields'] as $mlt_field) {
      // Solr 4 has a bug which results in numeric fields not being supported
      // in MLT queries.
      // Date fields don't seem to be supported at all.
      $version = $this->getSolrVersion();
      if ($fields[$mlt_field][0] === 'd' || (version_compare($version, '4', '==') && in_array($fields[$mlt_field][0], array('i', 'f')))) {
        continue;
      }

      $mlt_fl[] = $fields[$mlt_field];
      // For non-text fields, set minimum word length to 0.
      if (isset($index_fields[$mlt_field]) && !SearchApiUtility::isTextType($index_fields[$mlt_field]->getType())) {
        $solarium_query->addParam('f.' . $fields[$mlt_field] . '.mlt.minwl', 0);
      }
    }

    //$solarium_query->setHandler('mlt');
    $solarium_query->setMltFields($mlt_fl);
    /** @var \Solarium\Plugin\CustomizeRequest\CustomizeRequest $customizer */
    $customizer = $this->solr->getPlugin('customizerequest');
    $customizer->createCustomization('id')
      ->setType('param')
      ->setName('qt')
      ->setValue('mlt');
    // @todo Make sure these configurations are correct
    $solarium_query->setMinimumDocumentFrequency(1);
    $solarium_query->setMinimumTermFrequency(1);
  }

  /**
   * Adds spatial features to the search query.
   */
  public function setSpatial(Query $solarium_query, QueryInterface $query, $spatial_options = array(), $field_names) {
    foreach ($spatial_options as $i => $spatial) {
      // reset radius for each option
      unset($radius);

      if (empty($spatial['field']) || empty($spatial['lat']) || empty($spatial['lon'])) {
        continue;
      }

      $field = $field_names[$spatial['field']];
      $escaped_field = SearchApiSolrUtility::escapeFieldName($field);
      $point = ((float) $spatial['lat']) . ',' . ((float) $spatial['lon']);

      // Prepare the filter settings.
      if (isset($spatial['radius'])) {
        $radius = (float) $spatial['radius'];
      }

      $spatial_method = 'geofilt';
      if (isset($spatial['method']) && in_array($spatial['method'], array('geofilt', 'bbox'))) {
        $spatial_method = $spatial['method'];
      }

      $filter_queries = $solarium_query->getFilterQueries();
      // Change the fq facet ranges to the correct fq.
      foreach ($filter_queries as $key => $filter_query) {
        // If the fq consists only of a filter on this field, replace it with
        // a range.
        $preg_field = preg_quote($escaped_field, '/');
        if (preg_match('/^' . $preg_field . ':\["?(\*|\d+(?:\.\d+)?)"? TO "?(\*|\d+(?:\.\d+)?)"?\]$/', $filter_query, $matches)) {
          unset($filter_queries[$key]);
          if ($matches[1] && is_numeric($matches[1])) {
            $min_radius = isset($min_radius) ? max($min_radius, $matches[1]) : $matches[1];
          }
          if (is_numeric($matches[2])) {
            // Make the radius tighter accordingly.
            $radius = isset($radius) ? min($radius, $matches[2]) : $matches[2];
          }
        }
      }

      // If either a radius was given in the option, or a filter was
      // encountered, set a filter for the lowest value. If a lower boundary
      // was set (too), we can only set a filter for that if the field name
      // doesn't contains any colons.
      if (isset($min_radius) && strpos($field, ':') === FALSE) {
        $upper = isset($radius) ? " u=$radius" : '';
        $solarium_query->createFilterQuery($field)->setQuery("{!frange l=$min_radius$upper}geodist($field,$point)");
      }
      elseif (isset($radius)) {
        $solarium_query->createFilterQuery($field)->setQuery("{!$spatial_method pt=$point sfield=$field d=$radius}");
      }

      // @todo: Check if this object returns the correct value
      $sorts = $solarium_query->getSorts();
      // Change sort on the field, if set (and not already changed).
      if (isset($sorts[$spatial['field']]) && substr($sorts[$spatial['field']], 0, strlen($field)) === $field) {
        $sorts[$spatial['field']] = str_replace($field, "geodist($field,$point)", $sorts[$spatial['field']]);
      }

      // Change the facet parameters for spatial fields to return distance
      // facets.
      $facets = $solarium_query->getFacetSet();
      // @todo: Fix this so it takes it from the solarium query
      if (!empty($facets)) {
        if (!empty($facet_params['facet.field'])) {
          $facet_params['facet.field'] = array_diff($facet_params['facet.field'], array($field));
        }
        foreach ($facets as $delta => $facet) {
          if ($facet['field'] != $spatial['field']) {
            continue;
          }
          $steps = $facet['limit'] > 0 ? $facet['limit'] : 5;
          $step = (isset($radius) ? $radius : 100) / $steps;
          for ($k = $steps - 1; $k > 0; --$k) {
            $distance = $step * $k;
            $key = "spatial-$delta-$distance";
            $facet_params['facet.query'][] = "{!$spatial_method pt=$point sfield=$field d=$distance key=$key}";
          }
          foreach (array('limit', 'mincount', 'missing') as $setting) {
            unset($facet_params["f.$field.facet.$setting"]);
          }
        }
      }
    }

    // Normal sorting on location fields isn't possible.
    foreach (array_keys($solarium_query->getSorts()) as $sort) {
      if (substr($sort, 0, 3) === 'loc') {
        $solarium_query->removeSort($sort);
      }
    }
  }

  public function setSorts(Query $solarium_query, QueryInterface $query, $field_names_single_value = array()) {
    foreach ($query->getSorts() as $field => $order) {
      // The default Solr schema provides a virtual field named "random_SEED"
      // that can be used to randomly sort the results; the field is available
      // only at query-time.
      if ($field == 'search_api_random') {
        $params = $query->getOption('search_api_random_sort', array());
        // Random seed: getting the value from parameters or computing a new
        // one.
        $seed = !empty($params['seed']) ? $params['seed'] : mt_rand();
        $f = 'random_' . $seed;
      }
      else {
        $f = $field_names_single_value[$field];
        if (substr($f, 0, 3) == 'ss_') {
          $f = 'sort_' . substr($f, 3);
        }
      }

      $solarium_query->addSort($f, strtolower($order));
    }
  }

  public function setGrouping(Query $solarium_query, QueryInterface $query, $grouping_options = array(), $index_fields = array(), $field_names = array()) {
    $group_params['group'] = 'true';
    // We always want the number of groups returned so that we get pagers done
    // right.
    $group_params['group.ngroups'] = 'true';
    if (!empty($grouping_options['truncate'])) {
      $group_params['group.truncate'] = 'true';
    }
    if (!empty($grouping_options['group_facet'])) {
      $group_params['group.facet'] = 'true';
    }
    foreach ($grouping_options['fields'] as $collapse_field) {
      $type = $index_fields[$collapse_field]['type'];
      // Only single-valued fields are supported.
      if (SearchApiUtility::isTextType($type)) {
        $warnings[] = $this->t('Grouping is not supported for field @field. ' .
          'Only single-valued fields not indexed as "Fulltext" are supported.',
          array('@field' => $index_fields[$collapse_field]['name']));
        continue;
      }
      $group_params['group.field'][] = $field_names[$collapse_field];
    }
    if (empty($group_params['group.field'])) {
      unset($group_params);
    }
    else {
      if (!empty($grouping_options['group_sort'])) {
        foreach ($grouping_options['group_sort'] as $group_sort_field => $order) {
          if (isset($fields[$group_sort_field])) {
            $f = $fields[$group_sort_field];
            if (substr($f, 0, 3) == 'ss_') {
              $f = 'sort_' . substr($f, 3);
            }
            $order = strtolower($order);
            $group_params['group.sort'][] = $f . ' ' . $order;
          }
        }
        if (!empty($group_params['group.sort'])) {
          $group_params['group.sort'] = implode(', ', $group_params['group.sort']);
        }
      }
      if (!empty($grouping_options['group_limit']) && ($grouping_options['group_limit'] != 1)) {
        $group_params['group.limit'] = $grouping_options['group_limit'];
      }
    }
    foreach ($group_params as $param_id => $param_value) {
      $solarium_query->addParam($param_id, $param_value);
    }
  }

  /**
   * Sends a REST GET request to the Solr core and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   *
   * @return string
   *   The decoded response.
   */
  public function coreRestGet($path) {
    return $this->restRequest('core', $path);
  }

  /**
   * Sends a REST POST request to the Solr core and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   *
   * @param string $command_json
   *   The command to send encoded as JSON.
   *
   * @return string
   *   The decoded response.
   */
  public function coreRestPost($path, $command_json = '') {
    return $this->restRequest('core', $path, Request::METHOD_POST, $command_json);
  }

  /**
   * Sends a REST GET request to the Solr server and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   *
   * @return string
   *   The decoded response.
   */
  public function serverRestGet($path) {
    return $this->restRequest('server', $path);
  }

  /**
   * Sends a REST POST request to the Solr server and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   *
   * @param string $command_json
   *   The command to send encoded as JSON.
   *
   * @return string
   *   The decoded response.
   */
  public function serverRestPost($path, $command_json = '') {
    return $this->restRequest('server', $path, Request::METHOD_POST, $command_json);
  }

  /**
   * Sends a REST request to the Solr server endpoint and returns the result.
   *
   * @param string $endpoint
   *   The endpoint that refelcts the base URI.
   *
   * @param string $path
   *   The path to append to the base URI.
   *
   * @param string $method
   *   The HTTP request method.
   *
   * @param string $command_json
   *   The command to send encoded as JSON.
   *
   * @return string
   *   The decoded response.
   */
  protected function restRequest($endpoint, $path, $method = Request::METHOD_GET, $command_json = '') {
    $request = new Request();
    $request->setMethod($method);
    $request->addHeader('Accept: application/json');
    if (Request::METHOD_POST == $method) {
      $request->addHeader('Content-type: application/json');
      $request->setRawData($command_json);
    }
    $request->setHandler($path);
    $response = $this->solr->executeRequest($request, $endpoint);
    $output = Json::decode($response->getBody());
    // \Drupal::logger('search_api_solr')->info(print_r($output, true));
    if (!empty($output['errors'])) {
      throw new SearchApiSolrException('Error trying to send a REST request.' .
        "\nError message(s):" . print_r($output['errors'], TRUE));
    }
    return $output;
  }

}
