--

INSERT INTO `adm_grp_action_access`
        (`controller_name`, `action_name`,              `is_ajax`, `description`                  )
VALUES  ('tv-channels',     'move-channel-list-json',           1, 'Getting list of channels for moving'),
        ('tv-channels',     'move-channel-move-number',         1, 'Shifting list of channels after remove or add empty channel-item'),
        ('tv-channels',     'save-channel-epg-item',            1, 'Saving EPG for item from channels list'),
        ('tv-channels',     'get-channel-epg-item',             1, 'Getting EPG for item from channels list');
-- //@UNDO

--