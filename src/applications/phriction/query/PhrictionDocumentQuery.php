<?php

final class PhrictionDocumentQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $slugs;
  private $depths;
  private $slugPrefix;
  private $statuses;

  private $needContent;

  const ORDER_HIERARCHY = 'hierarchy';

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withSlugs(array $slugs) {
    $this->slugs = $slugs;
    return $this;
  }

  public function withDepths(array $depths) {
    $this->depths = $depths;
    return $this;
  }

  public function  withSlugPrefix($slug_prefix) {
    $this->slugPrefix = $slug_prefix;
    return $this;
  }

  public function withStatuses(array $statuses) {
    $this->statuses = $statuses;
    return $this;
  }

  public function needContent($need_content) {
    $this->needContent = $need_content;
    return $this;
  }

  protected function loadPage() {
    return $this->loadStandardPage($this->newResultObject());
  }

  public function newResultObject() {
    return new PhrictionDocument();
  }

  protected function willFilterPage(array $documents) {

    if ($documents) {
      $ancestor_slugs = array();
      foreach ($documents as $key => $document) {
        $document_slug = $document->getSlug();
        foreach (PhabricatorSlug::getAncestry($document_slug) as $ancestor) {
          $ancestor_slugs[$ancestor][] = $key;
        }
      }

      if ($ancestor_slugs) {
        $table = new PhrictionDocument();
        $conn_r = $table->establishConnection('r');
        $ancestors = queryfx_all(
          $conn_r,
          'SELECT * FROM %T WHERE slug IN (%Ls)',
          $document->getTableName(),
          array_keys($ancestor_slugs));
        $ancestors = $table->loadAllFromArray($ancestors);
        $ancestors = mpull($ancestors, null, 'getSlug');

        foreach ($ancestor_slugs as $ancestor_slug => $document_keys) {
          $ancestor = idx($ancestors, $ancestor_slug);
          foreach ($document_keys as $document_key) {
            $documents[$document_key]->attachAncestor(
              $ancestor_slug,
              $ancestor);
          }
        }
      }
    }
    // To view a Phriction document, you must also be able to view all of the
    // ancestor documents. Filter out documents which have ancestors that are
    // not visible.

    $document_map = array();
    foreach ($documents as $document) {
      $document_map[$document->getSlug()] = $document;
      foreach ($document->getAncestors() as $key => $ancestor) {
        if ($ancestor) {
          $document_map[$key] = $ancestor;
        }
      }
    }

    $filtered_map = $this->applyPolicyFilter(
      $document_map,
      array(PhabricatorPolicyCapability::CAN_VIEW));

    // Filter all of the documents where a parent is not visible.
    foreach ($documents as $document_key => $document) {
      // If the document itself is not visible, filter it.
      if (!isset($filtered_map[$document->getSlug()])) {
        $this->didRejectResult($documents[$document_key]);
        unset($documents[$document_key]);
        continue;
      }

      // If an ancestor exists but is not visible, filter the document.
      foreach ($document->getAncestors() as $ancestor_key => $ancestor) {
        if (!$ancestor) {
          continue;
        }

        if (!isset($filtered_map[$ancestor_key])) {
          $this->didRejectResult($documents[$document_key]);
          unset($documents[$document_key]);
          break;
        }
      }
    }

    if (!$documents) {
      return $documents;
    }

    if ($this->needContent) {
      $contents = id(new PhrictionContentQuery())
        ->setViewer($this->getViewer())
        ->setParentQuery($this)
        ->withIDs(mpull($documents, 'getContentID'))
        ->execute();
      $contents = mpull($contents, null, 'getID');

      foreach ($documents as $key => $document) {
        $content_id = $document->getContentID();
        if (empty($contents[$content_id])) {
          unset($documents[$key]);
          continue;
        }
        $document->attachContent($contents[$content_id]);
      }
    }

    return $documents;
  }

  protected function buildJoinClauseParts(AphrontDatabaseConnection $conn) {
    $joins = parent::buildJoinClauseParts($conn);

    if ($this->getOrderVector()->containsKey('updated')) {
      $content_dao = new PhrictionContent();
      $joins[] = qsprintf(
        $conn,
        'JOIN %T c ON d.contentID = c.id',
        $content_dao->getTableName());
    }

    return $joins;
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn,
        'd.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn,
        'd.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->slugs !== null) {
      $where[] = qsprintf(
        $conn,
        'd.slug IN (%Ls)',
        $this->slugs);
    }

    if ($this->statuses !== null) {
      $where[] = qsprintf(
        $conn,
        'd.status IN (%Ls)',
        $this->statuses);
    }

    if ($this->slugPrefix !== null) {
      $where[] = qsprintf(
        $conn,
        'd.slug LIKE %>',
        $this->slugPrefix);
    }

    if ($this->depths !== null) {
      $where[] = qsprintf(
        $conn,
        'd.depth IN (%Ld)',
        $this->depths);
    }

    return $where;
  }

  public function getBuiltinOrders() {
    return parent::getBuiltinOrders() + array(
      self::ORDER_HIERARCHY => array(
        'vector' => array('depth', 'title', 'updated'),
        'name' => pht('Hierarchy'),
      ),
    );
  }

  public function getOrderableColumns() {
    return parent::getOrderableColumns() + array(
      'depth' => array(
        'table' => 'd',
        'column' => 'depth',
        'reverse' => true,
        'type' => 'int',
      ),
      'title' => array(
        'table' => 'c',
        'column' => 'title',
        'reverse' => true,
        'type' => 'string',
      ),
      'updated' => array(
        'table' => 'd',
        'column' => 'contentID',
        'type' => 'int',
        'unique' => true,
      ),
    );
  }

  protected function getPagingValueMap($cursor, array $keys) {
    $document = $this->loadCursorObject($cursor);

    $map = array(
      'id' => $document->getID(),
      'depth' => $document->getDepth(),
      'updated' => $document->getContentID(),
    );

    foreach ($keys as $key) {
      switch ($key) {
        case 'title':
          $map[$key] = $document->getContent()->getTitle();
          break;
      }
    }

    return $map;
  }

  protected function willExecuteCursorQuery(
    PhabricatorCursorPagedPolicyAwareQuery $query) {
    $vector = $this->getOrderVector();

    if ($vector->containsKey('title')) {
      $query->needContent(true);
    }
  }

  protected function getPrimaryTableAlias() {
    return 'd';
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorPhrictionApplication';
  }

}
